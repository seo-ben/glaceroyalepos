<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedIp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BlockedIpController extends Controller
{
    /**
     * Liste toutes les adresses IP bloquées.
     */
    public function index()
    {
        $blockedIps = BlockedIp::orderBy('created_at', 'desc')->get();
        return response()->json($blockedIps);
    }

    /**
     * Bloque une nouvelle adresse IP.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ip_address' => 'required|ip|unique:blocked_ips,ip_address',
            'reason' => 'nullable|string|max:255',
        ], [
            'ip_address.required' => 'L\'adresse IP est requise.',
            'ip_address.ip' => 'L\'adresse IP n\'est pas valide.',
            'ip_address.unique' => 'Cette adresse IP est déjà bloquée.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors()
            ], 422);
        }

        $blockedIp = BlockedIp::create($validator->validated());
        \Illuminate\Support\Facades\Cache::put('blocked_ip_' . $blockedIp->ip_address, true, 3600);

        return response()->json([
            'status' => 'success',
            'message' => 'Adresse IP bloquée avec succès.',
            'data' => $blockedIp
        ], 201);
    }

    /**
     * Débloque une adresse IP.
     */
    public function destroy($id)
    {
        $blockedIp = BlockedIp::find($id);

        if (!$blockedIp) {
            return response()->json([
                'status' => 'error',
                'message' => 'Adresse IP bloquée non trouvée.'
            ], 404);
        }

        $blockedIp->delete();
        \Illuminate\Support\Facades\Cache::forget('blocked_ip_' . $blockedIp->ip_address);

        return response()->json([
            'status' => 'success',
            'message' => 'Adresse IP débloquée avec succès.'
        ]);
    }
}
