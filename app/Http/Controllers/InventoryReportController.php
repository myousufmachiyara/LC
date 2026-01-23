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
            $opPurchase = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->where('purchase_invoice_items.item_id', $itemId)
                ->where('purchase_invoices.invoice_date', '<', $from)->sum('purchase_invoice_items.quantity');

            $opSale = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->where('sale_invoice_items.product_id', $itemId)
                ->where('sale_invoices.date', '<', $from)->sum('sale_invoice_items.quantity');

            $openingQty = $opPurchase - $opSale;

            $purchases = DB::table('purchase_invoice_items')
                ->join('purchase_invoices', 'purchase_invoice_items.purchase_invoice_id', '=', 'purchase_invoices.id')
                ->join('products', 'purchase_invoice_items.item_id', '=', 'products.id')
                ->select(
                    'purchase_invoices.invoice_date as date', 
                    DB::raw("'Purchase' as type"), 
                    'purchase_invoices.invoice_no as description', 
                    'purchase_invoice_items.quantity as qty_in', 
                    DB::raw("0 as qty_out"),
                    'products.name as product',
                    DB::raw("'' as variation")
                )
                ->where('purchase_invoice_items.item_id', $itemId)
                ->whereBetween('purchase_invoices.invoice_date', [$from, $to]);

            $itemLedger = DB::table('sale_invoice_items')
                ->join('sale_invoices', 'sale_invoice_items.sale_invoice_id', '=', 'sale_invoices.id')
                ->join('products', 'sale_invoice_items.product_id', '=', 'products.id')
                ->select(
                    'sale_invoices.date as date', 
                    DB::raw("'Sale' as type"), 
                    'sale_invoices.invoice_no as description', 
                    DB::raw("0 as qty_in"), 
                    'sale_invoice_items.quantity as qty_out',
                    'products.name as product',
                    DB::raw("'' as variation")
                )
                ->where('sale_invoice_items.product_id', $itemId)
                ->whereBetween('sale_invoices.date', [$from, $to])
                ->union($purchases)
                ->orderBy('date', 'asc')
                ->get()
                ->map(fn($item) => (array)$item);
        }

        // ================= STOCK IN HAND (Location-Wise) =================
        if ($tab == 'SR') {
            $stockInHand = DB::table('products')
                ->select('products.id', 'products.name as product_name')
                ->when($itemId, fn($q) => $q->where('products.id', $itemId))
                ->get()
                ->flatMap(function ($product) use ($locationId, $locations, $costingMethod) {
                    
                    $targetLocations = $locationId ? $locations->where('id', $locationId) : $locations;

                    return $targetLocations->map(function ($loc) use ($product, $costingMethod) {
                        // Qty In: Purchases (location_id is in the items table)
                        $tIn = DB::table('purchase_invoice_items')
                            ->where('item_id', $product->id)
                            ->where('location_id', $loc->id) 
                            ->sum('quantity');

                        // Qty In: Transfers TO this location
                        $trIn = DB::table('stock_transfer_details')
                            ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                            ->where('stock_transfer_details.product_id', $product->id)
                            ->where('stock_transfers.to_location_id', $loc->id)
                            ->sum('quantity');

                        // Qty Out: Sales (location_id is in the items table)
                        $tOut = DB::table('sale_invoice_items')
                            ->where('product_id', $product->id)
                            ->where('location_id', $loc->id) 
                            ->sum('quantity');

                        // Qty Out: Transfers FROM this location
                        $trOut = DB::table('stock_transfer_details')
                            ->join('stock_transfers', 'stock_transfer_details.transfer_id', '=', 'stock_transfers.id')
                            ->where('stock_transfer_details.product_id', $product->id)
                            ->where('stock_transfers.from_location_id', $loc->id)
                            ->sum('quantity');

                        $qty = ($tIn + $trIn) - ($tOut + $trOut);

                        if ($qty == 0 && empty(request('location_id'))) return null;

                        $priceQuery = DB::table('purchase_invoice_items')->where('item_id', $product->id);
                        $price = match($costingMethod) {
                            'latest' => $priceQuery->latest('id')->value('price'),
                            'max' => $priceQuery->max('price'),
                            'min' => $priceQuery->min('price'),
                            default => $priceQuery->avg('price'),
                        } ?? 0;

                        return [
                            'product' => $product->product_name,
                            'location' => $loc->name,
                            'variation' => '-',
                            'quantity' => $qty,
                            'price' => $price,
                            'total' => $qty * $price
                        ];
                    });
                })->filter()->values();
        }

        // ================= STOCK TRANSFER =================
        if ($tab == 'STR') {
            $stockTransfers = DB::table('stock_transfers')
                ->join('stock_transfer_details', 'stock_transfers.id', '=', 'stock_transfer_details.transfer_id')
                ->join('products', 'stock_transfer_details.product_id', '=', 'products.id')
                ->leftJoin('product_variations', 'stock_transfer_details.variation_id', '=', 'product_variations.id')
                ->join('locations as from_loc', 'stock_transfers.from_location_id', '=', 'from_loc.id')
                ->join('locations as to_loc', 'stock_transfers.to_location_id', '=', 'to_loc.id')
                ->select(
                    'stock_transfers.date as date', 
                    'stock_transfers.id as reference',
                    'products.name as product', 
                    'product_variations.sku as variation',
                    'from_loc.name as from', 
                    'to_loc.name as to',
                    'stock_transfer_details.quantity'
                )
                ->whereBetween('stock_transfers.date', [$from, $to])
                ->when($request->from_location_id, fn($q) => $q->where('from_location_id', $request->from_location_id))
                ->when($request->to_location_id, fn($q) => $q->where('to_location_id', $request->to_location_id))
                ->get()
                ->map(fn($item) => (array)$item);
        }

        return view('reports.inventory_reports', compact(
            'products', 'locations', 'itemLedger', 'openingQty', 
            'stockInHand', 'stockTransfers', 'tab', 'from', 'to'
        ));
    }
}