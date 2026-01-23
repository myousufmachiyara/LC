<?php

namespace App\Http\Controllers;

use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceItem;
use App\Models\PurchaseInvoiceAttachment;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Voucher;
use App\Models\Location;
use App\Models\MeasurementUnit;
use App\Models\ChartOfAccounts; // assuming vendors are COA entries
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Services\myPDF;
use Carbon\Carbon;

class PurchaseInvoiceController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        
        // 1. Initialize query with relationships
        $query = PurchaseInvoice::with(['vendor', 'attachments']);

        // 2. Filter for Soft-Deleted items if requested
        if ($request->has('view_deleted')) {
            $query->onlyTrashed();
        }

        // 3. Privacy Logic: If NOT superadmin, restrict to own records
        if (!$user->hasRole('superadmin')) {
            $query->where('created_by', $user->id);
        }

        // 4. Execute with latest first
        $invoices = $query->latest()->get();

        return view('purchases.index', compact('invoices'));
    }

    public function create()
    {
        $products = Product::get();
        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();
        $units = MeasurementUnit::all();
        $locations = Location::all();

        return view('purchases.create', compact('products', 'vendors','units', 'locations'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'invoice_date' => 'required|date',
            'vendor_id' => 'required|exists:chart_of_accounts,id',
            'bill_no' => 'nullable|string|max:100',
            'ref_no' => 'nullable|string|max:100',
            'remarks' => 'nullable|string',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:products,id',
            'items.*.variation_id' => 'required|exists:product_variations,id',
            'items.*.location_id' => 'required|exists:locations,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|exists:measurement_units,id',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            // 1. Generate Invoice Number
            $lastInvoice = PurchaseInvoice::withTrashed()->orderBy('id', 'desc')->first();
            $nextNumber = $lastInvoice ? intval($lastInvoice->invoice_no) + 1 : 1;
            $invoiceNo = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

            // 2. Create Invoice Header
            $invoice = PurchaseInvoice::create([
                'invoice_no'   => $invoiceNo,
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
                'created_by'   => auth()->id(),
            ]);

            $totalAmount = 0; 

            // 3. Create Items, Calculate Total & Update Variation Stock
            foreach ($request->items as $itemData) {
                $qty = $itemData['quantity'] ?? 0;
                $price = $itemData['price'] ?? 0;
                $lineTotal = $qty * $price;
                $totalAmount += $lineTotal;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'],
                    'location_id'  => $itemData['location_id'], // Save location per item
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'] ?? '',
                    'price'        => $price,
                ]);

                // 🔹 NEW: Update the stock quantity in the ProductVariations table
                $variation = ProductVariation::findOrFail($itemData['variation_id']);
                if ($variation) {
                    $variation->increment('stock_quantity', $qty);
                }
            }

            // 4. Find Debit Account (Inventory/Stock) by Code (Safer than Name)
            $inventoryAccount = ChartOfAccounts::where('account_code', '104001')->first();
            
            if (!$inventoryAccount) {
                throw new \Exception("Default Inventory account (104001) not found.");
            }

            // 5. Create Voucher (The Accounting Entry)
            Voucher::create([
                'date'         => $request->invoice_date,
                'voucher_type' => 'journal',
                'ac_dr_sid'    => $inventoryAccount->id, // Debit: Stock in Hand
                'ac_cr_sid'    => $request->vendor_id,    // Credit: Vendor
                'amount'       => $totalAmount,
                'reference'    => "PI-" . $invoice->id,  // Prefix helps in searching
            ]);

            // 6. Handle Attachments
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Purchase Invoice created successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Purchase Invoice Store Error: ' . $e->getMessage());
            return back()->withInput()->withErrors(['error' => $e->getMessage()]);
        }
    }

    public function edit($id)
    {
        $invoice = PurchaseInvoice::with([
            'items.product.variations', 
            'items.variation', 
            'attachments'
        ])->findOrFail($id);

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get();
        $products = Product::with('variations')->select('id', 'name', 'measurement_unit')->get();
        $units = MeasurementUnit::all();
        $locations = Location::all(); // <--- Added this

        return view('purchases.edit', compact('invoice', 'vendors', 'products', 'units', 'locations'));
    }

    public function update(Request $request, $id)
    {
        $request->validate([
            'invoice_date' => 'required|date',
            'vendor_id' => 'required|exists:chart_of_accounts,id',
            'bill_no' => 'nullable|string|max:100',
            'ref_no' => 'nullable|string|max:100',
            'remarks' => 'nullable|string',
            'attachments.*' => 'nullable|file|mimes:jpg,jpeg,png,pdf,zip|max:2048',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:products,id',
            'items.*.variation_id' => 'required|exists:product_variations,id',
            'items.*.location_id' => 'required|exists:locations,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit' => 'required|exists:measurement_units,id',
            'items.*.price' => 'required|numeric|min:0',
        ]);

        DB::beginTransaction();

        try {
            $invoice = PurchaseInvoice::with('items')->findOrFail($id);

            // 1. REVERSE OLD STOCK: Decrement stock for all existing items before deleting them
            foreach ($invoice->items as $oldItem) {
                $oldVariation = ProductVariation::find($oldItem->variation_id);
                if ($oldVariation) {
                    $oldVariation->decrement('stock_quantity', $oldItem->quantity);
                }
            }

            // 2. Update Invoice Main Details
            $invoice->update([
                'vendor_id'    => $request->vendor_id,
                'invoice_date' => $request->invoice_date,
                'bill_no'      => $request->bill_no,
                'ref_no'       => $request->ref_no,
                'remarks'      => $request->remarks,
            ]);

            // 3. Clear Old Items
            $invoice->items()->delete();
            
            $totalItemsAmount = 0;

            // 4. Create New Items & APPLY NEW STOCK
            foreach ($request->items as $itemData) {
                if (empty($itemData['item_id'])) continue;

                $qty = (float)$itemData['quantity'];
                $price = (float)$itemData['price'];
                $lineTotal = $qty * $price;
                $totalItemsAmount += $lineTotal;

                $invoice->items()->create([
                    'item_id'      => $itemData['item_id'],
                    'variation_id' => $itemData['variation_id'],
                    'location_id'  => $itemData['location_id'], // Save location per item
                    'quantity'     => $qty,
                    'unit'         => $itemData['unit'] ?? '',
                    'price'        => $price,
                ]);

                // Increment stock for the new/updated variation
                $variation = ProductVariation::find($itemData['variation_id']);
                if ($variation) {
                    $variation->increment('stock_quantity', $qty);
                }
            }

            // 5. Accounting Adjustment (Voucher)
            $inventoryAccount = ChartOfAccounts::where('account_code', '104001')->first();
            if (!$inventoryAccount) throw new \Exception("Inventory Account 104001 not found.");

            $finalVoucherAmount = $totalItemsAmount + (float)($request->convance_charges ?? 0) + (float)($request->labour_charges ?? 0) - (float)($request->bill_discount ?? 0);

            // Use the same reference logic as store: "PI-" . $id
            $voucher = Voucher::updateOrCreate(
                ['reference' => "PI-" . $invoice->id, 'voucher_type' => 'journal'],
                [
                    'date'      => $request->invoice_date,
                    'ac_dr_sid' => $inventoryAccount->id,
                    'ac_cr_sid' => $request->vendor_id,
                    'amount'    => $finalVoucherAmount,
                    'remarks'   => "Updated Purchase Invoice #{$invoice->invoice_no}",
                ]
            );

            // 6. Handle Attachments (Keep existing and add new)
            if ($request->hasFile('attachments')) {
                foreach ($request->file('attachments') as $file) {
                    $path = $file->store('purchase_invoices', 'public');
                    $invoice->attachments()->create([
                        'file_path'     => $path,
                        'original_name' => $file->getClientOriginalName(),
                        'file_type'     => $file->getClientMimeType(),
                    ]);
                }
            }

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Purchase Invoice updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update Error: ' . $e->getMessage());
            return back()->withErrors(['error' => 'Failed to update: ' . $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        $invoice = PurchaseInvoice::with('items')->findOrFail($id);

        DB::beginTransaction();

        try {
            // 1. Reverse Inventory (Decrement stock)
            foreach ($invoice->items as $item) {
                $variation = ProductVariation::find($item->variation_id);
                if ($variation) {
                    $variation->decrement('stock_quantity', $item->quantity);
                }
            }

            // 2. Remove Accounting Voucher
            Voucher::where('reference', "PI-" . $invoice->id)->delete();

            // 3. Soft Delete the Invoice (and optionally hard delete items)
            $invoice->items()->delete();
            $invoice->delete(); // Uses SoftDeletes if configured in Model

            DB::commit();
            return redirect()->route('purchase_invoices.index')->with('success', 'Invoice deleted and stock adjusted.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete Error: ' . $e->getMessage());
            return back()->with('error', 'Failed to delete invoice.');
        }
    }

    public function restore($id)
    {
        $invoice = PurchaseInvoice::onlyTrashed()->with('items')->findOrFail($id);
        
        DB::beginTransaction();
        try {
            $invoice->restore();
            
            // Restore associated items and put stock back in warehouse
            foreach ($invoice->items()->onlyTrashed()->get() as $item) {
                $item->restore();
                $variation = ProductVariation::find($item->variation_id);
                if ($variation) {
                    $variation->increment('stock_quantity', $item->quantity);
                }
            }

            // Restore the accounting entry
            Voucher::onlyTrashed()->where('reference', "PI-" . $invoice->id)->restore();
            
            DB::commit();
            return redirect()->back()->with('success', 'Invoice and Stock restored successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->with('error', 'Restore failed: ' . $e->getMessage());
        }
    }

    // public function show($id)
    // {
    //     $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation', 'attachments', 'creator'])
    //         ->findOrFail($id);

    //     return view('purchases.show', compact('invoice'));
    // }

    public function print($id)
    {
        // Eager load variations and products to prevent N+1 issues
        $invoice = PurchaseInvoice::with(['vendor', 'items.product', 'items.variation'])->findOrFail($id);

        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

        // Document Information
        $pdf->SetCreator('BillTrix');
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('PUR-' . $invoice->invoice_no);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(TRUE, 20);

        $pdf->AddPage();

        // --- Header Section ---
        $logoPath = public_path('assets/img/billtrix-logo-black.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'PURCHASE INVOICE', 0, 1, 'R');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');

        $pdf->Ln(5);

        // --- Vendor Details ---
        $vendorName = $invoice->vendor->name ?? 'N/A';
        $vendorHtml = '
        <table width="40%" border="1" cellpadding="3" style="font-size:10px;">
            <tr>
                <td width="40%"><b>Vendor:</b></td>
                <td width="60%">' . $vendorName . '</td>
            </tr>
            <tr>
                <td><b>Bill No:</b></td>
                <td>' . ($invoice->bill_no ?? '-') . '</td>
            </tr>
            <tr>
                <td><b>Ref:</b></td>
                <td>' . ($invoice->ref_no ?? '-') . '</td>
            </tr>
        </table>';
        $pdf->writeHTML($vendorHtml, true, false, false, false, '');

        $pdf->Ln(5);

        // --- Items Table ---
        $html = '
        <table border="1" cellpadding="5" style="font-size:10px;">
            <thead>
                <tr style="background-color:#f2f2f2; font-weight:bold; text-align:center;">
                    <th width="5%">#</th>
                    <th width="35%">Item Description</th>
                    <th width="20%">Variation</th>
                    <th width="10%">Qty</th>
                    <th width="15%">Price</th>
                    <th width="15%">Total</th>
                </tr>
            </thead>
            <tbody>';

        $totalAmount = 0;
        foreach ($invoice->items as $index => $item) {
            $variationName = $item->variation->sku ?? $item->variation->variation_name ?? '-';
            $lineTotal = $item->quantity * $item->price;
            $totalAmount += $lineTotal;

            $html .= '
                <tr>
                    <td width="5%" style="text-align:center;">' . ($index + 1) . '</td>
                    <td width="35%">' . ($item->product->name) . '</td>
                    <td width="20%" style="text-align:center;">' . $variationName . '</td>
                    <td width="10%" style="text-align:center;">' . number_format($item->quantity, 2) . '</td>
                    <td width="15%" style="text-align:right;">' . number_format($item->price, 2) . '</td>
                    <td width="15%" style="text-align:right;">' . number_format($lineTotal, 2) . '</td>
                </tr>';
        }

        $html .= '
                <tr style="font-weight:bold; background-color:#fafafa;">
                    <td colspan="5" style="text-align:right;">Total Amount</td>
                    <td style="text-align:right;">' . number_format($totalAmount, 2) . '</td>
                </tr>
            </tbody>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        // --- Remarks ---
        if ($invoice->remarks) {
            $pdf->Ln(2);
            $pdf->SetFont('helvetica', 'I', 9);
            $pdf->MultiCell(0, 5, 'Remarks: ' . $invoice->remarks, 0, 'L');
        }

        // --- Signatures ---
        $pdf->SetFont('helvetica', '', 10);
        $ySign = $pdf->GetY() + 25;
        
        // Check if we are too close to the bottom to place signatures
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

        return $pdf->Output('PI_' . $invoice->invoice_no . '.pdf', 'I');
    }
}
