<?php

namespace App\Http\Controllers;

use App\Jobs\RunBusinessResearchJob;
use App\Models\BusinessResearch;
use Illuminate\Http\Request;

class BusinessResearchController extends Controller
{
    public function index()
    {
        $researches = BusinessResearch::latest()->paginate(15);

        return view('business-research.index', compact('researches'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'business_name' => 'required|string|max:500',
            'address' => 'nullable|string|max:1000',
            'website' => 'nullable|string|max:500',
        ]);

        $research = BusinessResearch::create([
            'business_name' => $request->business_name,
            'address' => $request->address,
            'website' => $request->website ? trim($request->website) : null,
            'status' => 'pending',
        ]);

        // Always run synchronously — avoids stale queue workers calling old OpenRouter code
        RunBusinessResearchJob::dispatchSync($research->id);

        return redirect()->route('business-research.show', $research)
            ->with('success', 'Business research started. Results will appear shortly.');
    }

    public function show(BusinessResearch $businessResearch)
    {
        return view('business-research.show', ['research' => $businessResearch]);
    }

    public function status(BusinessResearch $businessResearch)
    {
        return response()->json([
            'status' => $businessResearch->status,
            'complete' => $businessResearch->isComplete(),
            'owner_name' => $businessResearch->owner_name,
            'payment_processor' => $businessResearch->payment_processor,
            'confidence' => $businessResearch->confidence,
        ]);
    }

    public function retry(BusinessResearch $businessResearch)
    {
        $businessResearch->update([
            'status' => 'pending',
            'error_message' => null,
        ]);

        RunBusinessResearchJob::dispatchSync($businessResearch->id);

        return redirect()->route('business-research.show', $businessResearch)
            ->with('success', 'Research queued again.');
    }

    public function destroy(BusinessResearch $businessResearch)
    {
        $businessResearch->delete();

        return redirect()->route('business-research.index')->with('success', 'Research deleted.');
    }
}
