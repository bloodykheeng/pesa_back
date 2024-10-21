<?php

namespace App\Http\Controllers\API;

use App\Models\Faq;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;

class FaqController extends Controller
{
      /**
     * Display a listing of FAQs.
     */
    public function index(Request $request)
    {
        // Initialize the query builder for the FAQ model
        $query = Faq::with(['createdBy', 'updatedBy']);

        // Filter FAQs by question if 'question' query parameter is provided
        $question = $request->query('question');
        if (isset($question)) {
            $query->where('question', 'like', "%$question%");
        }

        $query->latest();
        // Retrieve the list of FAQs
        $faqs = $query->get();

        // Return the list as a JSON response
        return response()->json(['data' => $faqs]);
    }

    /**
     * Store a newly created FAQ in the database.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
        ]);

        // Create a new FAQ entry
        $faq = Faq::create([
            'question' => $validated['question'],
            'answer' => $validated['answer'],
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        // Return a JSON response indicating success
        return response()->json(['message' => 'FAQ created successfully', 'data' => $faq], 201);
    }

    /**
     * Display the specified FAQ.
     */
    public function show($id)
    {
        // Find the FAQ by ID or return a 404 error if not found
        $faq = Faq::findOrFail($id);

        // Return the FAQ data as a JSON response
        return response()->json($faq);
    }

    /**
     * Update the specified FAQ in storage.
     */
    public function update(Request $request, $id)
    {
        // Find the FAQ by ID
        $faq = Faq::find($id);
        if (!$faq) {
            return response()->json(['message' => 'FAQ not found'], 404);
        }

        // Validate the request data
        $validated = $request->validate([
            'question' => 'required|string|max:255',
            'answer' => 'required|string',
        ]);

        // Update the FAQ's data
        $faq->update([
            'question' => $validated['question'],
            'answer' => $validated['answer'],
            'updated_by' => Auth::id(),
        ]);

        // Return a success response
        return response()->json(['message' => 'FAQ updated successfully', 'data' => $faq]);
    }

    /**
     * Remove the specified FAQ from storage.
     */
    public function destroy($id)
    {
        // Find the FAQ by ID
        $faq = Faq::find($id);

        if (!$faq) {
            return response()->json(['message' => 'FAQ not found'], 404);
        }

        // Delete the FAQ
        $faq->delete();

        // Return a success message
        return response()->json("FAQ '$faq->question' deleted successfully", 200);
    }

    public function get_faqs()
    {
        // Fetch all FAQs
        $faqs = Faq::all();
        return response()->json($faqs);
    }
}