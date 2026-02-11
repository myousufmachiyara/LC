<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\Location;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class InventoryReportController extends Controller
{
    public function inventoryReports(Request $request)
    {
        $tab = $request->get('tab', 'IL');
        $itemId = $request->get('item_id'); 
        $locationId = $request->get('location_id');
        $from = $request->get('from_date', date('Y-m-01'));
        $to = $request->get('to_date', date('Y-m-d'));
        $costingMethod = $request->get('costing_method', 'avg');

        $products = Product::with('variations')->orderBy('name', 'asc')->get();
        $locations = Location::all();
        
        $itemLedger = collect();
        $openingQty = 0;
        $stockInHand = collect();
        $stockTransfers = collect();

        // ================= ITEM LEDGER =================
        if ($tab == 'IL' && $itemId) {
            // 1. Calculate Opening Balance
            $opPurchase = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('item_id', $itemId)
                ->whereNull('purchase_invoices.deleted_at') // Filter deleted invoices
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->where('invoice_date', '<', $from)->sum('quantity');

            $opSale = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('product_id', $itemId)
                ->whereNull('sale_invoices.deleted_at') // Filter deleted sales
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->where('date', '<', $from)->sum('quantity');

            $opTransIn = DB::table('stock_transfer_details')
                ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                ->where('product_id', $itemId)
                ->whereNull('stock_transfers.deleted_at') // Filter deleted transfers
                ->when($locationId, fn($q) => $q->where('to_location_id', $locationId))
                ->where('date', '<', $from)->sum('quantity');

            $opTransOut = DB::table('stock_transfer_details')
                ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                ->where('product_id', $itemId)
                ->whereNull('stock_transfers.deleted_at') // Filter deleted transfers
                ->when($locationId, fn($q) => $q->where('from_location_id', $locationId))
                ->where('date', '<', $from)->sum('quantity');

            $openingQty = ($opPurchase + $opTransIn) - ($opSale + $opTransOut);

            // 2. Current Period Transactions (Add whereNull to all unions)
            $purchases = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->select('invoice_date as date', DB::raw("'Purchase' as type"), 'invoice_no as description', 'quantity as qty_in', DB::raw("0 as qty_out"))
                ->where('item_id', $itemId)
                ->whereNull('purchase_invoices.deleted_at')
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('invoice_date', [$from, $to]);

            $sales = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->select('date', DB::raw("'Sale' as type"), 'invoice_no as description', DB::raw("0 as qty_in"), 'quantity as qty_out')
                ->where('product_id', $itemId)
                ->whereNull('sale_invoices.deleted_at')
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->whereBetween('date', [$from, $to]);

            $transIn = DB::table('stock_transfer_details')
                ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                ->select('date', DB::raw("'Transfer IN' as type"), DB::raw("'Internal Movement' as description"), 'quantity as qty_in', DB::raw("0 as qty_out"))
                ->where('product_id', $itemId)
                ->whereNull('stock_transfers.deleted_at')
                ->when($locationId, fn($q) => $q->where('to_location_id', $locationId))
                ->whereBetween('date', [$from, $to]);

            $transOut = DB::table('stock_transfer_details')
                ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                ->select('date', DB::raw("'Transfer OUT' as type"), DB::raw("'Internal Movement' as description"), DB::raw("0 as qty_in"), 'quantity as qty_out')
                ->where('product_id', $itemId)
                ->whereNull('stock_transfers.deleted_at')
                ->when($locationId, fn($q) => $q->where('from_location_id', $locationId))
                ->whereBetween('date', [$from, $to]);

            $itemLedger = $purchases->union($sales)->union($transIn)->union($transOut)
                ->orderBy('date', 'asc')->get()->map(fn($item) => (array)$item);
        }

        // ================= STOCK IN HAND (LOT WISE) =================
        if ($tab == 'SR') {
            $stockInHand = \App\Models\StockLot::with(['product.measurementUnit', 'variation', 'location'])
                ->when($itemId, fn($q) => $q->where('product_id', $itemId))
                ->when($locationId, fn($q) => $q->where('location_id', $locationId))
                ->where('quantity', '>', 0)
                ->get()
                ->map(function ($lot) {
                    return [
                        'product'    => $lot->product->name ?? 'N/A',
                        'variation'  => $lot->variation->sku ?? 'Standard',
                        'location'   => $lot->location->name ?? 'N/A',
                        'lot_number' => $lot->lot_number,
                        'quantity'   => $lot->quantity,
                        'unit'       => $lot->product->measurementUnit->shortcode ?? '',
                        'created_at' => $lot->created_at->format('d-M-Y'),
                    ];
                });
        }

        // ================= STOCK TRANSFERS =================
        if ($tab == 'STR') {
            $stockTransfers = DB::table('stock_transfers')
                ->join('stock_transfer_details', 'stock_transfers.id', '=', 'stock_transfer_details.transfer_id')
                ->join('products', 'stock_transfer_details.product_id', '=', 'products.id')
                ->join('locations as from_loc', 'stock_transfers.from_location_id', '=', 'from_loc.id')
                ->join('locations as to_loc', 'stock_transfers.to_location_id', '=', 'to_loc.id')
                ->select(
                    'stock_transfers.date', 
                    'stock_transfers.id as reference', 
                    'products.name as product', 
                    'from_loc.name as from', 
                    'to_loc.name as to', 
                    'stock_transfer_details.quantity'
                )
                ->whereNull('stock_transfers.deleted_at')
                ->whereBetween('stock_transfers.date', [$from, $to])
                ->when($request->from_location_id, function($q) use ($request) {
                    return $q->where('stock_transfers.from_location_id', $request->from_location_id);
                })
                ->when($request->to_location_id, function($q) use ($request) {
                    return $q->where('stock_transfers.to_location_id', $request->to_location_id);
                })
                ->get() // This returns a Collection of stdClass objects
                ->map(function ($item) {
                    return (array) $item; // Force conversion to array so $st['date'] works
                });
        }

        return view('reports.inventory_reports', compact('products', 'locations', 'itemLedger', 'openingQty', 'stockInHand', 'stockTransfers', 'tab', 'from', 'to'));
    }
}