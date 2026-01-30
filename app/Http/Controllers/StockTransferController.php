<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\StockTransferDetail;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class StockTransferController extends Controller
{
    // List all stock transfers
    public function index()
    {
        try {
            $transfers = StockTransfer::with(['fromLocation', 'toLocation', 'details.product', 'details.variation'])
                ->orderBy('date', 'desc')
                ->get();

            return view('stock-transfer.index', compact('transfers'));
        } catch (\Exception $e) {
            Log::error('Failed to load stock transfers: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return back()->with('error', 'Failed to load stock transfers.');
        }
    }

    // Show create form
    public function create()
    {
        try {
            $locations = Location::all();
            $products = Product::with('measurementUnit')->get();
            return view('stock-transfer.create', compact('locations', 'products'));
        } catch (\Exception $e) {
            Log::error('Failed to load create stock transfer form: '.$e->getMessage());
            return back()->with('error', 'Failed to load create form.');
        }
    }

    // Store new stock transfer
    public function store(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id',
            'remarks' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            \DB::beginTransaction();

            $transfer = StockTransfer::create([
                'date' => $request->date,
                'remarks' => $request->remarks,
                'from_location_id' => $request->from_location_id,
                'to_location_id' => $request->to_location_id,
                'created_by' => Auth::id(),
            ]);

            foreach ($request->items as $item) {
                StockTransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            \DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Stock transfer created successfully.');
        } catch (\Exception $e) {
            \DB::rollBack();
            Log::error('Failed to store stock transfer: '.$e->getMessage(), ['trace'=>$e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to create stock transfer.');
        }
    }

    // Show edit form
    public function edit($id)
    {
        try {
            $transfer = StockTransfer::with(['details'])->findOrFail($id);
            $locations = Location::all();
            $products = Product::with('variations')->get();
            return view('stock-transfer.edit', compact('transfer', 'locations', 'products'));
        } catch (\Exception $e) {
            Log::error('Failed to load edit stock transfer form: '.$e->getMessage());
            return back()->with('error', 'Failed to load edit form.');
        }
    }

    // Update existing stock transfer
    public function update(Request $request, $id)
    {
        $request->validate([
            'date' => 'required|date',
            'from_location_id' => 'required|exists:locations,id',
            'to_location_id' => 'required|exists:locations,id',
            'remarks' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
        ]);

        try {
            \DB::beginTransaction();

            $transfer = StockTransfer::findOrFail($id);
            $transfer->update([
                'date' => $request->date,
                'from_location_id' => $request->from_location_id,
                'to_location_id' => $request->to_location_id,
                'remarks' => $request->remarks,
            ]);

            // Delete old details and insert new
            $transfer->details()->delete();
            foreach ($request->items as $item) {
                StockTransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'variation_id' => $item['variation_id'] ?? null,
                    'quantity' => $item['quantity'],
                ]);
            }

            \DB::commit();
            return redirect()->route('stock_transfer.index')->with('success', 'Stock transfer updated successfully.');
        } catch (\Exception $e) {
            \DB::rollBack();
            Log::error('Failed to update stock transfer: '.$e->getMessage(), ['trace'=>$e->getTraceAsString()]);
            return back()->withInput()->with('error', 'Failed to update stock transfer.');
        }
    }

    // Delete a stock transfer
    public function destroy($id)
    {
        try {
            $transfer = StockTransfer::findOrFail($id);
            $transfer->delete();
            return redirect()->route('stock_transfers.index')->with('success', 'Stock transfer deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Failed to delete stock transfer: '.$e->getMessage());
            return back()->with('error', 'Failed to delete stock transfer.');
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
                        <th width="8%">#</th>
                        <th width="38%">Product</th>
                        <th width="38%">Variation</th>
                        <th width="16%">Quantity</th>
                    </tr>
                </thead>
                <tbody>';

            foreach ($transfer->details as $index => $item) {
                // Note: Changed $item->product->sku to ->name based on typical product structures
                $productName = $item->product->name ?? '-'; 
                $variationSku = $item->variation->sku ?? '-';
                $unit = $item->product->measurementUnit->shortcode ?? '';

                $html .= '
                    <tr>
                        <td width="8%" style="text-align:center;">' . ($index + 1) . '</td>
                        <td width="38%">' . $productName . '</td>
                        <td width="38%" style="text-align:center;">' . $variationSku . '</td>
                        <td width="16%" style="text-align:center;">' . number_format($item->quantity, 2) . ' ' . $unit . '</td>
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
}
