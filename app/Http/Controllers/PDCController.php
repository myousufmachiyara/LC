<?php

namespace App\Http\Controllers;

use App\Models\PostDatedCheque;
use App\Models\ChartOfAccounts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PDCController extends Controller
{
    public function index() {
        $cheques = PostDatedCheque::get();
        
        return view('pdc.index', compact('cheques'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => 'required|in:receivable,payable',
            'cheque_number' => 'required',
            'cheque_date' => 'required|date',
            'amount' => 'required|numeric',
            'bank_name' => 'required',
            'party_name' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $validated['status'] = ($request->type == 'receivable') ? 'received' : 'issued';
        $validated['created_by'] = Auth::id();

        PostDatedCheque::create($validated);
        return redirect()->back()->with('success', 'Cheque recorded.');
    }

    public function transfer(Request $request, $id)
    {
        $request->validate(['transfer_to_party' => 'required|string']);
        
        $cheque = PostDatedCheque::findOrFail($id);
        
        if($cheque->type != 'receivable' || $cheque->status != 'received') {
            return back()->with('error', 'Only received inward cheques can be transferred.');
        }

        $cheque->update([
            'status' => 'transferred',
            'transfer_to_party' => $request->transfer_to_party,
            'remarks' => 'Transferred to ' . $request->transfer_to_party
        ]);

        return back()->with('success', 'Cheque transferred to ' . $request->transfer_to_party);
    }

    // Add an edit method to serve JSON for your modal
    public function edit($id)
    {
        $cheque = PostDatedCheque::findOrFail($id);
        return response()->json($cheque);
    }

    // Add an update method for the Edit Modal
    public function update(Request $request, $id)
    {
        $cheque = PostDatedCheque::findOrFail($id);
        
        $validated = $request->validate([
            'type' => 'required|in:receivable,payable',
            'cheque_number' => 'required|unique:post_dated_cheques,cheque_number,'.$id,
            'cheque_date'   => 'required|date',
            'amount'        => 'required|numeric',
            'bank_name'     => 'required|string',
            'party_name'    => 'required|string',
            'remarks'       => 'nullable|string',
        ]);

        // Logic to update status if the type was changed during edit
        // Only update status if the cheque is still in its initial state
        if ($cheque->status == 'received' || $cheque->status == 'issued') {
            $validated['status'] = ($request->type == 'receivable') ? 'received' : 'issued';
        }

        $cheque->update($validated);
        
        return redirect()->route('pdc.index')->with('success', 'Cheque updated.');
    }

    public function deposit($id)
    {
        $cheque = PostDatedCheque::findOrFail($id);
        $cheque->update(['status' => 'deposited', 'deposited_at' => now()]);
        return back()->with('success', 'Cheque marked as deposited.');
    }

    public function clear($id)
    {
        $cheque = PostDatedCheque::findOrFail($id);
        
        // Logic: If inward, it must be 'deposited' first. 
        // If outward, it can go from 'issued' to 'cleared'.
        if ($cheque->type == 'payable' && $cheque->status != 'issued') {
            return back()->with('error', 'Only issued cheques can be cleared.');
        }

        $cheque->update(['status' => 'cleared', 'cleared_at' => now()]);
        return back()->with('success', 'Cheque cleared.');
    }

    public function bounce(Request $request, $id)
    {
        $cheque = PostDatedCheque::findOrFail($id);
        $cheque->update(['status' => 'bounced', 'remarks' => $request->remarks]);
        return back()->with('warning', 'Cheque marked as bounced.');
    }

    public function destroy($id)
    {
        PostDatedCheque::findOrFail($id)->delete();
        return back()->with('success', 'Record deleted.');
    }
}