<?php

namespace App\Http\Controllers;

use App\Models\Notifikacija;
use App\Models\User;
use Illuminate\Http\Request;

class NotifikacijaController extends Controller
{
    // GET /notifikacije/moja
    public function moje()
    {
        $user   = auth()->user();
        $userId = $user->id;

        // Admin i bibliotekar nemaju lične notifikacije
        if ($user->isAdminOrBibliotekar()) {
            $notifikacije = collect();
        } else {
            $notifikacije = Notifikacija::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->get();
        }

        return view('notifikacije.not', compact('notifikacije', 'user'));
    }

    // PATCH /notifikacije/{id}/procitano — briše notifikaciju (označi kao pročitano)
    public function procitano($id)
    {
        $userId = auth()->id();

        $notifikacija = Notifikacija::where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if (!$notifikacija) {
            return response()->json([
                'success' => false,
                'message' => 'Notifikacija nije pronađena',
            ], 404);
        }

        $notifikacija->delete();

        return response()->json(['success' => true]);
    }
}