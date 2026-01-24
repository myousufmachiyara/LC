<?php

namespace App\Http\Controllers;

use App\Models\SaleInvoice;
use App\Models\SaleInvoiceItem;
use App\Models\SaleItemCustomization;
use App\Models\PurchaseInvoiceItem;
use App\Models\ChartOfAccounts;
use App\Models\Product;
use App\Models\MeasurementUnit;
use App\Models\Location;
use App\Models\ProductVariation;
use App\Models\Voucher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SaleInvoiceController extends Controller
{
    public function index()
    {
        $invoices = SaleInvoice::with('items.product', 'account')->latest()->get();
        return view('sales.index', compact('invoices'));
    }

    public function create()
    {
        $products = Product::with(['variations'])->orderBy('name', 'asc')->get()
            ->map(function ($product) {
                $productTotalStock = 0;

                foreach ($product->variations as $v) {
                    // 1. Total Purchases & Sales
                    $purchased = DB::table('purchase_invoice_items')->where('variation_id', $v->id)->sum('quantity');
                    $sold = DB::table('sale_invoice_items')->where('variation_id', $v->id)->sum('quantity');

                    // 2. Transfers IN (Sum quantity where this variation was sent TO a location)
                    // Note: Replace 'transfer_id' with your actual foreign key column name
                    $transferredIn = DB::table('stock_transfer_details')
                        ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                        ->where('stock_transfer_details.variation_id', $v->id)
                        ->sum('stock_transfer_details.quantity');

                    // 3. Transfers OUT (Usually in a simple system, Transfers In = Transfers Out globally, 
                    // but we calculate it here for future-proofing location-specific logic)
                    $transferredOut = DB::table('stock_transfer_details')
                        ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                        ->where('stock_transfer_details.variation_id', $v->id)
                        ->sum('stock_transfer_details.quantity');

                    // Global Stock Calculation
                    // In a global context, transfers usually cancel out unless you are filtering by a specific location.
                    $v->current_stock = $purchased - $sold; 
                    $productTotalStock += $v->current_stock;
                }

                $product->real_time_stock = $productTotalStock;
                return $product;
            });

        $vendors = ChartOfAccounts::where('account_type', 'vendor')->get(); // For Purchase Invoice
        $customers = ChartOfAccounts::where('account_type', 'customer')->get();
        $paymentAccounts = ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get();
        $locations = Location::all();
        $units = MeasurementUnit::all(); // Assuming you have a Unit model

        return view('sales.create', compact('products', 'customers', 'paymentAccounts', 'locations', 'units'));
    }
    
    public function store(Request $request)
    {
        $validated = $request->validate([
            'date'               => 'required|date',
            'account_id'         => 'required|exists:chart_of_accounts,id',
            'type'               => 'required|in:cash,credit',
            'discount'           => 'nullable|numeric|min:0',
            'remarks'            => 'nullable|string',
            'items'              => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.location_id' => 'required|exists:locations,id',
            'items.*.sale_price' => 'required|numeric|min:0',
            'items.*.quantity'   => 'required|numeric|min:1',
            // Payment receiving fields
            'payment_account_id' => 'nullable|exists:chart_of_accounts,id',
            'amount_received'    => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            // Auto-generate Invoice Number
            $lastInvoice = SaleInvoice::withTrashed()->orderBy('id', 'desc')->first();
            $nextNumber = $lastInvoice ? intval($lastInvoice->invoice_no) + 1 : 1;
            $invoiceNo = str_pad($nextNumber, 6, '0', STR_PAD_LEFT);

            // 1. Create Invoice
            $invoice = SaleInvoice::create([
                'invoice_no' => $invoiceNo,
                'date'       => $validated['date'],
                'account_id' => $validated['account_id'],
                'type'       => $validated['type'],
                'discount'   => $validated['discount'] ?? 0,
                'remarks'    => $validated['remarks'],
                'created_by' => Auth::id(),
            ]);

            // 2. Save Items & Track Net Total for Voucher validation
            $totalBill = 0;
            foreach ($validated['items'] as $item) {
                $invoiceItem = SaleInvoiceItem::create([
                    'sale_invoice_id' => $invoice->id,
                    'product_id'      => $item['product_id'],
                    'variation_id'    => $item['variation_id'] ?? null,
                    'location_id'     => $item['location_id'], // Save location per item
                    'sale_price'      => $item['sale_price'],
                    'quantity'        => $item['quantity'],
                    'discount'        => 0,
                ]);
                
                $totalBill += ($item['sale_price'] * $item['quantity']);
            }

            $netTotal = $totalBill - ($validated['discount'] ?? 0);

            // 3. Record Sales Revenue Entry
            $salesAccount = ChartOfAccounts::where('name', 'Sales Revenue')
                ->orWhere('account_type', 'revenue')
                ->first();

            if (!$salesAccount) throw new \Exception('Sales Revenue account not found.');

            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['date'],
                'ac_dr_sid'    => $validated['account_id'], // Debit Customer
                'ac_cr_sid'    => $salesAccount->id,        // Credit Sales
                'amount'       => $netTotal,
                'remarks'      => "Sales Invoice #{$invoiceNo}",
                'reference'    => $invoice->id,
            ]);

            // 4. Handle Payment (If Cash or partial payment received)
            if ($request->filled('payment_account_id') && $request->amount_received > 0) {
                Voucher::create([
                    'voucher_type' => 'receipt',
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $validated['payment_account_id'], // Debit Cash/Bank
                    'ac_cr_sid'    => $validated['account_id'],         // Credit Customer
                    'amount'       => $validated['amount_received'],
                    'remarks'      => "Payment received for Invoice #{$invoiceNo}",
                    'reference'    => $invoice->id,
                ]);
            }

            // 5. NEW: Record Cost of Goods Sold (COGS) Entry
            $inventoryAccount = ChartOfAccounts::where('name', 'Stock in Hand')->first();
            $cogsAccount = ChartOfAccounts::where('account_type', 'cogs')->first();

            if ($inventoryAccount && $cogsAccount) {
                $totalCost = 0;
                foreach ($validated['items'] as $item) {
                    // Find the LATEST purchase of this product to get the most recent price
                    $latestPurchase = PurchaseInvoiceItem::where('item_id', $item['product_id'])
                        ->with('invoice') // Assuming relation exists
                        ->latest()
                        ->first();

                    if ($latestPurchase) {
                        $unitPrice = $latestPurchase->purchase_price;
                        
                        // Calculate Bilty share (Total Bilty / Total Items in that purchase)
                        $totalQtyInPurchase = PurchaseInvoiceItem::where('purchase_invoice_id', $latestPurchase->purchase_invoice_id)->sum('quantity');
                        $biltyCharge = $latestPurchase->purchaseInvoice->bilty_charges ?? 0;
                        
                        $landedCostPerUnit = $unitPrice + ($biltyCharge / ($totalQtyInPurchase ?: 1));
                        
                        $totalCost += ($landedCostPerUnit * $item['quantity']);
                    }
                }

                Voucher::create([
                    'voucher_type' => 'journal',
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $cogsAccount->id,      
                    'ac_cr_sid'    => $inventoryAccount->id, 
                    'amount'       => $totalCost,
                    'remarks'      => "COGS (Landed Cost) for Invoice #{$invoiceNo}",
                    'reference'    => $invoice->id,
                ]);
            }

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Sale Invoice and Payment processed.');

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[SaleInvoice] Store failed: ' . $e->getMessage());
            return back()->withInput()->with('error', 'Error saving invoice.');
        }
    }

    public function edit($id)
    {
        $invoice = SaleInvoice::with(['items', 'account'])->findOrFail($id);
        
        // Calculate real-time stock exactly like the create method
        $products = Product::orderBy('name', 'asc')
            ->withSum('purchaseInvoices as total_purchased', 'quantity')
            ->withSum('saleInvoices as total_sold', 'quantity')
            ->get()
            ->map(function ($product) {
                $product->real_time_stock = ($product->total_purchased ?? 0) - ($product->total_sold ?? 0);
                return $product;
            });

        $amountReceived = Voucher::where('ac_cr_sid', $invoice->account_id)
            ->where('remarks', 'LIKE', "%Invoice #{$invoice->invoice_no}%")
            ->sum('amount');
        $locations = Location::all();

        return view('sales.edit', [
            'invoice' => $invoice,
            'products' => $products, // Now contains real_time_stock
            'customers' => ChartOfAccounts::where('account_type', 'customer')->get(),
            'paymentAccounts' => ChartOfAccounts::whereIn('account_type', ['cash', 'bank'])->get(),
            'amountReceived' => $amountReceived,
            'locations' => $locations
        ]);
    }

    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'date'                 => 'required|date',
            'account_id'           => 'required|exists:chart_of_accounts,id',
            'type'                 => 'required|in:cash,credit',
            'discount'             => 'nullable|numeric|min:0',
            'remarks'              => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_id'   => 'required|exists:products,id',
            'items.*.variation_id' => 'nullable|exists:product_variations,id',
            'items.*.location_id'  => 'required|exists:locations,id',            
            'items.*.sale_price'   => 'required|numeric|min:0',
            'items.*.quantity'     => 'required|numeric|min:0.01',
            'payment_account_id'   => 'nullable|exists:chart_of_accounts,id',
            'amount_received'      => 'nullable|numeric|min:0',
        ]);

        DB::beginTransaction();
        try {
            $invoice = SaleInvoice::with('items')->findOrFail($id);
            $invoiceNo = $invoice->invoice_no;

            // 2. Update Invoice Header
            $invoice->update([
                'date'       => $validated['date'],
                'account_id' => $validated['account_id'],
                'type'       => $validated['type'],
                'discount'   => $validated['discount'] ?? 0,
                'remarks'    => $validated['remarks'],
            ]);

            // 3. Clear existing items and Re-insert
            $invoice->items()->delete();
            
            $totalBill = 0;
            $totalCost = 0;

            foreach ($validated['items'] as $item) {
                SaleInvoiceItem::create([
                    'sale_invoice_id' => $invoice->id,
                    'product_id'      => $item['product_id'],
                    'variation_id'    => $item['variation_id'] ?? null,
                    'location_id'     => $item['location_id'],
                    'sale_price'      => $item['sale_price'],
                    'quantity'        => $item['quantity'],
                    'discount'        => 0,
                ]);

                $totalBill += ($item['sale_price'] * $item['quantity']);

                // COGS Logic
                $latestPurchase = PurchaseInvoiceItem::where('item_id', $item['product_id'])
                    ->when(!empty($item['variation_id']), function($q) use ($item) {
                        return $q->where('variation_id', $item['variation_id']);
                    })
                    ->latest()
                    ->first();

                if ($latestPurchase && $latestPurchase->purchaseInvoice) {
                    $unitPurchasePrice = $latestPurchase->purchase_price;
                    $pInvoice = $latestPurchase->purchaseInvoice; 
                    
                    $totalQtyInBatch = PurchaseInvoiceItem::where('purchase_invoice_id', $latestPurchase->purchase_invoice_id)->sum('quantity');
                    $biltyCharge = $pInvoice->bilty_charges ?? 0;
                    
                    $landedCostPerUnit = $unitPurchasePrice + ($biltyCharge / ($totalQtyInBatch ?: 1));
                    $totalCost += ($landedCostPerUnit * $item['quantity']);
                }
            }

            $netTotal = $totalBill - ($validated['discount'] ?? 0);
            $invoice->update(['net_amount' => $netTotal]);

            // 4. Update Financial Vouchers (Sales & COGS)
            // Note: We only delete Journal vouchers here, not the Receipt one
            Voucher::where('reference', (string)$invoice->id)->where('voucher_type', 'journal')->delete();

            $inventoryAc = ChartOfAccounts::where('name', 'Stock in Hand')->first();
            $cogsAc      = ChartOfAccounts::where('account_type', 'cogs')->first();
            $salesAc     = ChartOfAccounts::where('account_type', 'revenue')->first();

            // Journal: Sales
            Voucher::create([
                'voucher_type' => 'journal',
                'date'         => $validated['date'],
                'ac_dr_sid'    => $validated['account_id'],
                'ac_cr_sid'    => $salesAc->id ?? null,
                'amount'       => $netTotal,
                'remarks'      => "Updated: Sales Invoice #{$invoiceNo}",
                'reference'    => $invoice->id,
            ]);

            // Journal: COGS
            if ($inventoryAc && $cogsAc && $totalCost > 0) {
                Voucher::create([
                    'voucher_type' => 'journal',
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $cogsAc->id,      
                    'ac_cr_sid'    => $inventoryAc->id, 
                    'amount'       => $totalCost,
                    'remarks'      => "Updated: COGS (Landed) #{$invoiceNo}",
                    'reference'    => $invoice->id,
                ]);
            }

            // 5. UPDATE OR CREATE RECEIPT VOUCHER
            $paymentVoucher = Voucher::where('reference', (string)$invoice->id)->where('voucher_type', 'receipt')->first();

            if ($request->filled('amount_received') && $request->amount_received > 0 && $request->filled('payment_account_id')) {
                $receiptData = [
                    'date'         => $validated['date'],
                    'ac_dr_sid'    => $validated['payment_account_id'],
                    'ac_cr_sid'    => $validated['account_id'],
                    'amount'       => $validated['amount_received'],
                    'remarks'      => "Payment for Invoice #{$invoiceNo}",
                    'reference'    => $invoice->id,
                ];

                if ($paymentVoucher) {
                    $paymentVoucher->update($receiptData);
                } else {
                    Voucher::create(array_merge(['voucher_type' => 'receipt'], $receiptData));
                }
            } elseif ($paymentVoucher) {
                // If the user cleared the amount or account, remove the payment record
                $paymentVoucher->delete();
            }

            DB::commit();
            return redirect()->route('sale_invoices.index')->with('success', 'Invoice and Payment updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error("Invoice Update Error: " . $e->getMessage());
            return back()->with('error', 'Update Failed: ' . $e->getMessage())->withInput();
        }
    }

    public function print($id)
    {
        // 1. Fetch Invoice with Relations
        $invoice = SaleInvoice::with(['account', 'items.product', 'items.variation'])->findOrFail($id);

        // 2. Fetch Amount Received from Vouchers
        $amountReceived = Voucher::where('ac_cr_sid', $invoice->account_id)
            ->where('remarks', 'LIKE', "%Invoice #{$invoice->invoice_no}%")
            ->sum('amount');

        // 3. Initialize TCPDF
        $pdf = new \TCPDF();
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetCreator(PDF_CREATOR);
        $pdf->SetAuthor('Lucky Corporation');
        $pdf->SetTitle('SALE-' . $invoice->invoice_no);

        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 10);

        // --- Header Section ---
        $logoPath = public_path('assets/img/billtrix-logo-black.png');
        if (file_exists($logoPath)) {
            $pdf->Image($logoPath, 15, 12, 35);
        }

        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetXY(110, 12);
        $pdf->Cell(85, 10, 'SALE INVOICE', 0, 1, 'R');
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetXY(110, 20);
        $pdf->Cell(85, 5, 'Invoice #: ' . $invoice->invoice_no, 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Date: ' . Carbon::parse($invoice->invoice_date)->format('d-M-Y'), 0, 1, 'R');
        $pdf->SetX(110);
        $pdf->Cell(85, 5, 'Customer: ' . $invoice->account->name, 0, 1, 'R');

        $pdf->Ln(10);

        /* ---------------- Items Table with Separate Variation Column ---------------- */
        $html = '
        <table border="1" cellpadding="4" style="text-align:center;font-size:10px;">
            <tr style="font-weight:bold; background-color:#f5f5f5;">
                <th width="5%">#</th>
                <th width="35%">Item Name</th>
                <th width="20%">Variation</th>
                <th width="10%">Qty</th>
                <th width="15%">Price</th>
                <th width="15%">Total</th>
            </tr>';

        $count = 0;
        $totalQty = 0;
        $subTotal = 0;

        foreach ($invoice->items as $item) {
            $count++;
            $lineTotal = $item->sale_price * $item->quantity;

            $html .= '
            <tr>
                <td>' . $count . '</td>
                <td style="text-align:left">' . ($item->product->name ?? '-') . '</td>
                <td style="text-align:left">' . ($item->variation->sku ?? '-') . '</td>
                <td>' . number_format($item->quantity, 2) . ' ' . $item->product->measurementUnit->shortcode. '</td>
                <td>' . number_format($item->sale_price, 2) . '</td>
                <td>' . number_format($lineTotal, 2) . '</td>
            </tr>';

            $totalQty += $item->quantity;
            $subTotal += $lineTotal;
        }

        // Calculations
        $invoiceDiscount = $invoice->discount ?? 0;
        $netTotal = $subTotal - $invoiceDiscount;
        $balanceDue = $netTotal - $amountReceived;

        /* ---------------- Table Footer ---------------- */
        $html .= '
        <tr>
            <td colspan="3" align="right" bgcolor="#f5f5f5"><b>Total Quantity</b></td>
            <td><b>' . number_format($totalQty, 2) . '</b></td>
            <td align="right" bgcolor="#f5f5f5"><b>Sub Total</b></td>
            <td align="right"><b>' . number_format($subTotal, 2) . '</b></td>
        </tr>';

        if ($invoiceDiscount > 0) {
            $html .= '
            <tr>
                <td colspan="5" align="right">Less: Discount</td>
                <td align="right">' . number_format($invoiceDiscount, 2) . '</td>
            </tr>';
        }

        $html .= '
        <tr style="background-color:#f5f5f5;">
            <td colspan="5" align="right"><b>Net Payable</b></td>
            <td align="right"><b>' . number_format($netTotal, 2) . '</b></td>
        </tr>
        <tr>
            <td colspan="5" align="right">Amount Received</td>
            <td align="right" style="color:green;">' . number_format($amountReceived, 2) . '</td>
        </tr>
        <tr style="background-color:#eeeeee;">
            <td colspan="5" align="right"><b>Remaining Balance</b></td>
            <td align="right" style="color:red;"><b>' . number_format($balanceDue, 2) . '</b></td>
        </tr>
        </table>';

        $pdf->writeHTML($html, true, false, false, false, '');

        /* ---------------- Remarks ---------------- */
        if (!empty($invoice->remarks)) {
            $pdf->Ln(2);
            $pdf->writeHTML('<p style="font-size:9px;"><b>Remarks:</b> ' . nl2br($invoice->remarks) . '</p>', true, false, false, false, '');
        }

        /* ---------------- Footer Signatures ---------------- */
        if ($pdf->GetY() > 240) {
            $pdf->AddPage();
        }

        $pdf->Ln(30);
        $yPosition = $pdf->GetY();
        $lineWidth = 60;

        $pdf->Line(20, $yPosition, 20 + $lineWidth, $yPosition);
        $pdf->Line(130, $yPosition, 130 + $lineWidth, $yPosition);

        $pdf->SetY($yPosition + 2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetX(20);
        $pdf->Cell($lineWidth, 5, 'Customer Signature', 0, 0, 'C');
        $pdf->SetX(130);
        $pdf->Cell($lineWidth, 5, 'Authorized Signature', 0, 0, 'C');

        return $pdf->Output('Invoice_' . $invoice->invoice_no . '.pdf', 'I');
    }

}
