<?php
// app/Http/Controllers/StockTransferController.php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Models\StockLot;
use App\Models\Location;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockTransferController extends Controller
{
    private static $lastGeneratedInRequest = null;

    public function index(Request $request)
    {
        $query = StockTransfer::with(['fromLocation', 'toLocation', 'creator'])->withCount('details'); // This allows you to use $transfer->details_count

        // Search filters
        if ($request->filled('from_date')) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->filled('to_date')) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        if ($request->filled('from_location_id')) {
            $query->where('from_location_id', $request->from_location_id);
        }

        if ($request->filled('to_location_id')) {
            $query->where('to_location_id', $request->to_location_id);
        }

        $transfers = $query->latest('date')->paginate(20);
        $locations = Location::orderBy('name')->get();

        return view('stock_transfer.index', compact('transfers', 'locations'));
    }

    public function create()
    {
        $locations = Location::orderBy('name')->get();
        $products = Product::with('measurementUnit')
            ->orderBy('name')
            ->get();

        return view('stock_transfer.create', compact('locations', 'products'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id|different:from_location_id',
            'remarks' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            DB::beginTransaction();

            $fromLocation = Location::findOrFail($request->from_location_id);
            $toLocation = Location::findOrFail($request->to_location_id);

            $transfer = StockTransfer::create([
                'date' => $request->date,
                'remarks' => $request->remarks,
                'from_location_id' => $request->from_location_id,
                'to_location_id' => $request->to_location_id,
                'created_by' => Auth::id(),
            ]);

            $isStockIn = ($fromLocation->type === 'vendor');
            $isSale = ($toLocation->type === 'customer');

            // Generate ONE lot number to be used for this entire batch if it's a Stock In
            $sharedLotNumber = $this->generateLotNumber();

            foreach ($request->items as $item) {
                $productId = $item['product_id'];
                $variationId = $item['variation_id'] ?? null;
                $quantity = $item['quantity'];
                
                if ($isStockIn) {
                    // Use the pre-generated shared number unless a specific one was provided
                    $finalLotNumber = ($item['new_lot_number'] ?? null) ?: $sharedLotNumber;
                    $this->addToLot($toLocation->id, $productId, $variationId, $finalLotNumber, $quantity);
                } else {
                    $finalLotNumber = $item['lot_number'] ?? null;
                    if (!$finalLotNumber) throw new \Exception("Lot number required.");

                    $this->deductFromLot($fromLocation->id, $productId, $variationId, $finalLotNumber, $quantity);
                    
                    if (!$isSale) {
                        $this->addToLot($toLocation->id, $productId, $variationId, $finalLotNumber, $quantity);
                    }
                }
                
                StockTransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $productId,
                    'variation_id' => $variationId,
                    'lot_number' => $finalLotNumber,
                    'vendor_lot_number' => $item['vendor_lot_number'] ?? null,
                    'quantity' => $quantity,
                ]);
            }

            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Transfer processed.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function edit($id)
    {
        $transfer = StockTransfer::with('details')->findOrFail($id);
        $locations = Location::orderBy('name')->get();
        $products = Product::with('measurementUnit')->orderBy('name')->get();

        return view('stock_transfer.edit', compact('transfer', 'locations', 'products'));
    }

    public function update(Request $request, $id)
    {
        $this->validateRequest($request);

        try {
            DB::beginTransaction();

            // Load with old locations specifically to ensure reversal happens at the right place
            $transfer = StockTransfer::with(['details', 'fromLocation', 'toLocation'])->findOrFail($id);

            // 1. Reverse using OLD location data
            $this->reverseStockMovements($transfer);

            // 2. Clear old details
            $transfer->details()->delete();

            // 3. Update parent with NEW location data
            $transfer->update($request->only(['date', 'remarks', 'from_location_id', 'to_location_id']));
            
            // Refresh the model so processStockItems sees the NEW locations
            $transfer->refresh(); 

            // 4. Process NEW items
            $this->processStockItems($transfer, $request->items);

            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Transfer updated.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update failed: ' . $e->getMessage());
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            DB::beginTransaction();
            $transfer = StockTransfer::with(['fromLocation', 'toLocation', 'details'])->findOrFail($id);

            $this->reverseStockMovements($transfer);

            $transfer->delete();
            DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Deleted and stock adjusted.');
        } catch (\Exception $e) {
            DB::rollBack();
            return back()->with('error', $e->getMessage());
        }
    }

    public function getAvailableLots(Request $request)
    {
        $product = Product::find($request->product_id);
        
        // If product doesn't require lot tracking, return empty
        if ($product && isset($product->track_lots) && !$product->track_lots) {
            return response()->json([
                'lots' => [],
                'product_track_lots' => false
            ]);
        }
        
        $lots = StockLot::where('location_id', $request->location_id)
            ->where('product_id', $request->product_id)
            ->when($request->variation_id, function($q) use ($request) {
                return $q->where('variation_id', $request->variation_id);
            })
            ->where('quantity', '>', 0)
            ->orderBy('created_at', 'asc')
            ->select('lot_number', 'quantity',)
            ->get();
        
        return response()->json([
            'lots' => $lots,
            'product_track_lots' => true
        ]);
    }

    private function generateLotNumber()
    {
        $prefix = 'LOT-';
        
        // Check DB for the absolute max
        $lastNumber = StockLot::lockForUpdate()
            ->selectRaw("MAX(CAST(SUBSTRING(lot_number, 5) AS UNSIGNED)) as max_number")
            ->value('max_number') ?? 0;

        // Compare against what we've generated in this loop/request
        $currentMax = max($lastNumber, self::$lastGeneratedInRequest ?? 0);
        $sequence = $currentMax + 1;
        
        self::$lastGeneratedInRequest = $sequence;

        return $prefix . str_pad($sequence, 5, '0', STR_PAD_LEFT);
    }

    private function deductFromLot($locationId, $productId, $variationId, $lotNumber, $quantity)
    {
        $lot = StockLot::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where('lot_number', $lotNumber)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->lockForUpdate()
            ->first();
        
        if (!$lot) {
            throw new \Exception("Lot {$lotNumber} not found at the source location.");
        }
        
        if ($lot->quantity < $quantity) {
            throw new \Exception("Insufficient quantity in lot {$lotNumber}. Available: {$lot->quantity}, Required: {$quantity}");
        }
        
        $lot->decrement('quantity', $quantity);
        
        // Delete lot if quantity is 0
        if ($lot->fresh()->quantity <= 0) {
            $lot->delete();
        }
    }

    private function addToLot($locationId, $productId, $variationId, $lotNumber, $quantity)
    {
        $existing = StockLot::where('location_id', $locationId)
            ->where('product_id', $productId)
            ->where('lot_number', $lotNumber)
            ->when($variationId, fn($q) => $q->where('variation_id', $variationId))
            ->first();
        
        if ($existing) {
            $existing->increment('quantity', $quantity);
        } else {
            StockLot::create([
                'location_id' => $locationId,
                'product_id' => $productId,
                'variation_id' => $variationId,
                'lot_number' => $lotNumber,
                'quantity' => $quantity,
            ]);
        }
    }

    public function print($id)
    {
        try {
            // 1. Clear any accidental output (whitespace, notices) that cause header errors
            if (ob_get_length()) {
                ob_end_clean();
            }

            // Eager load relationships
            $transfer = StockTransfer::with(['fromLocation', 'toLocation', 'details.product', 'details.variation'])
                ->findOrFail($id);

            // Initialize TCPDF
            $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

            // Document Information
            $pdf->SetCreator('BillTrix');
            $pdf->SetAuthor('Lucky Corporation');
            $pdf->SetTitle('ST-' . $transfer->id);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(TRUE, 20);

            $pdf->AddPage();

            // --- Header Section (Logo) ---
            $logoPath = public_path('assets/img/billtrix-logo-black.png');
            if (file_exists($logoPath)) {
                $pdf->Image($logoPath, 15, 12, 35);
            }

            // --- Header Title & Info ---
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->SetXY(110, 12);
            $pdf->Cell(85, 10, 'STOCK TRANSFER', 0, 1, 'R');
            
            $pdf->SetFont('helvetica', '', 10);
            $pdf->SetXY(110, 20);
            $pdf->Cell(85, 5, 'Transfer #: ' . $transfer->id, 0, 1, 'R');
            $pdf->SetX(110);
            $pdf->Cell(85, 5, 'Date: ' . \Carbon\Carbon::parse($transfer->date)->format('d-M-Y'), 0, 1, 'R');

            $pdf->Ln(5);

            // --- Location Details Table ---
            $locationHtml = '
            <table width="50%" border="1" cellpadding="3" style="font-size:10px;">
                <tr>
                    <td width="40%" style="background-color:#f2f2f2;"><b>From Location:</b></td>
                    <td width="60%">' . ($transfer->fromLocation->name ?? '-') . '</td>
                </tr>
                <tr>
                    <td style="background-color:#f2f2f2;"><b>To Location:</b></td>
                    <td>' . ($transfer->toLocation->name ?? '-') . '</td>
                </tr>
            </table>';
            $pdf->writeHTML($locationHtml, true, false, false, false, '');

            $pdf->Ln(5);

            // --- Items Table ---
            $html = '
                <table border="1" cellpadding="5" style="font-size:10px;">
                    <thead>
                        <tr style="background-color:#f2f2f2; font-weight:bold; text-align:center;">
                            <th width="6%">#</th>
                            <th width="28%">Product</th>
                            <th width="22%">Variation</th>
                            <th width="20%">Lot No</th>
                            <th width="24%">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>';

                foreach ($transfer->details as $index => $item) {

                    $productName = $item->product->name ?? '-'; 
                    $variationSku = $item->variation->sku ?? '-';
                    $lotNumber = $item->lot_number ? $item->lot_number : 'Auto-generated';
                    $unit = $item->product->measurementUnit->shortcode ?? '';

                    $html .= '
                        <tr>
                            <td width="6%" style="text-align:center;">' . ($index + 1) . '</td>
                            <td width="28%">' . $productName . '</td>
                            <td width="22%" style="text-align:center;">' . $variationSku . '</td>
                            <td width="20%" style="text-align:center;">' . $lotNumber . '</td>
                            <td width="24%" style="text-align:center;">' . number_format($item->quantity, 2) . ' ' . $unit . '</td>
                        </tr>';
                }
            $html .= '</tbody></table>';

            $pdf->writeHTML($html, true, false, false, false, '');

            // --- Remarks ---
            if ($transfer->remarks) {
                $pdf->Ln(2);
                $pdf->SetFont('helvetica', 'I', 9);
                $pdf->MultiCell(0, 5, 'Remarks: ' . $transfer->remarks, 0, 'L');
            }

            // --- Signatures ---
            $pdf->SetFont('helvetica', '', 10);
            $ySign = $pdf->GetY() + 25;
            
            if ($ySign > 250) {
                $pdf->AddPage();
                $ySign = 30;
            }

            $pdf->Line(15, $ySign, 75, $ySign);
            $pdf->SetXY(15, $ySign + 2);
            $pdf->Cell(60, 5, 'Prepared By', 0, 0, 'C');

            $pdf->Line(135, $ySign, 195, $ySign);
            $pdf->SetXY(135, $ySign + 2);
            $pdf->Cell(60, 5, 'Authorized Signature', 0, 0, 'C');
             // 2. Output the PDF as a string and return via Laravel Response
            $content = $pdf->Output('ST_' . $transfer->id . '.pdf', 'S');
            
            return response($content)
                ->header('Content-Type', 'application/pdf')
                ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
                ->header('Pragma', 'no-cache')
                ->header('Expires', '0');

        } catch (\Exception $e) {
            \Log::error('Failed to print stock transfer: '.$e->getMessage());
            return back()->with('error', 'Failed to generate stock transfer PDF. Error: ' . $e->getMessage());
        }
    }

    // --- Private Logic Helpers ---

    private function validateRequest(Request $request)
    {
        return $request->validate([
            'date' => 'required|date',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id|different:from_location_id',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);
    }

    private function processStockItems(StockTransfer $transfer, array $items)
    {
        $fromLocation = Location::findOrFail($transfer->from_location_id);
        $toLocation = Location::findOrFail($transfer->to_location_id);

        $isStockIn = ($fromLocation->type === 'vendor');
        $isSale = ($toLocation->type === 'customer');
        
        // Generate one shared lot for the whole request if it's a Stock In
        $sharedLotNumber = $this->generateLotNumber();

        foreach ($items as $item) {
            $productId = $item['product_id'];
            $variationId = $item['variation_id'] ?? null;
            $quantity = $item['quantity'];

            if ($isStockIn) {
                // Priority: Explicit Lot > Shared Lot
                $finalLotNumber = ($item['new_lot_number'] ?? null) ?: $sharedLotNumber;
                $this->addToLot($transfer->to_location_id, $productId, $variationId, $finalLotNumber, $quantity);
            } else {
                $finalLotNumber = $item['lot_number'] ?? null;
                if (!$finalLotNumber) throw new \Exception("Lot number required.");

                $this->deductFromLot($transfer->from_location_id, $productId, $variationId, $finalLotNumber, $quantity);

                if (!$isSale) {
                    $this->addToLot($transfer->to_location_id, $productId, $variationId, $finalLotNumber, $quantity);
                }
            }

            $transfer->details()->create([
                'product_id' => $productId,
                'variation_id' => $variationId,
                'lot_number' => $finalLotNumber,
                'vendor_lot_number' => $item['vendor_lot_number'] ?? null,
                'quantity' => $quantity,
            ]);
        }
    }

    private function reverseStockMovements(StockTransfer $transfer)
    {
        // Use the loaded relationships
        $fromLocation = $transfer->fromLocation;
        $toLocation   = $transfer->toLocation;

        foreach ($transfer->details as $detail) {
            // Reverse Source: Put back into source if it wasn't a purchase from a vendor
            if ($fromLocation && $fromLocation->type !== 'vendor') {
                $this->addToLot($fromLocation->id, $detail->product_id, $detail->variation_id, $detail->lot_number, $detail->quantity);
            }

            // Reverse Destination: Remove from destination if it wasn't a sale to a customer
            if ($toLocation && $toLocation->type !== 'customer') {
                $this->deductFromLot($toLocation->id, $detail->product_id, $detail->variation_id, $detail->lot_number, $detail->quantity);
            }
        }
    }
}