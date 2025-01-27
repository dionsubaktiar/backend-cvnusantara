<?php

namespace App\Http\Controllers;

use App\Models\Data; // Assuming you have a Data model
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class DataController extends Controller
{
    // Get all data records
    public function index()
    {
        // Retrieve all data and group by month and year
        $data = Data::whereNotNull('tanggal')->orderBy('tanggal')->get()->groupBy(function ($item) {
            return Carbon::parse($item->tanggal)->format('F Y'); // Group by month and year
        });

        // Transform grouped data into a more readable format
        $results = [];
        foreach ($data as $monthYear => $items) {
            $results[$monthYear] = [
                'count' => $items->count(),
                'data' => $items->map(function ($item) {
                    return [
                        'id' => $item->id,
                        'nopol' => $item->nopol,
                        'driver' => $item->driver,
                        'tanggal' => $item->tanggal,
                        'status' => $item->status,
                        'status_sj' => $item->status_sj,
                        'tanggal_update_sj' => $item->tanggal_update_sj,
                        'harga' => $item->harga,
                        'uj' => $item->uj,
                    ];
                }),
            ];
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Data grouped by month and year retrieved successfully',
            'dataByMonth' => $results,
        ]);
    }

    // Sum data grouped by month and year
    public function sum()
    {
        $currentYear = Carbon::now()->year;
        $results = [];

        // Fetch distinct years from the data
        $years = Data::selectRaw('YEAR(tanggal) as year')->distinct()->pluck('year');

        foreach ($years as $year) {
            for ($month = 1; $month <= 12; $month++) {
                $monthName = Carbon::createFromDate($year, $month, 1)->format('F');
                $monthYear = sprintf('%s-%02d', $year, $month);

                // Query data for the specific month and year
                $data = Data::whereRaw('DATE_FORMAT(tanggal, "%Y-%m") = ?', [$monthYear])->get();

                // Count the different status types for the month
                $countSukses = $data->where('status', 'confirmed')->count();
                $countPending = $data->where('status', 'pending')->count();
                $countGagal = $data->where('status', 'canceled')->count();

                // If all counts are 0, skip this month and do not add it to the results
                if ($countSukses === 0 && $countPending === 0 && $countGagal === 0) {
                    continue;
                }

                // Calculate margin for the month
                $marginSum = $data->where('status', 'confirmed')->reduce(function ($carry, $item) {
                    return $carry + ($item->harga - $item->uj); // Calculate margin (harga - uj)
                }, 0);

                // Determine if the result is profit or loss
                $untungrugi = $marginSum < 0 ? 'RUGI' : 'UNTUNG';

                // Add the month data to the results array
                $results["$monthName $year"] = [
                    'monthYear' => $monthYear,
                    'untungrugi' => $untungrugi,
                    'marginSum' => $marginSum,
                    'countSukses' => $countSukses,
                    'countPending' => $countPending,
                    'countGagal' => $countGagal,
                ];
            }
        }

        return response()->json([
            'status' => 'success',
            'dataByMonthYear' => $results,
        ]);
    }

    // Create a new data record
    public function store(Request $request)
    {
        $validatedData = Validator::make($request->all(), [
            'tanggal' => 'required|date',
            'nopol' => 'required|string',
            'driver' => 'string',
            'origin' => 'required|string',
            'destinasi' => 'required|string',
            'uj' => 'required|numeric',
            'harga' => 'required|numeric',
            'status' => 'required|string',

        ]);

        if ($validatedData->fails()) {
            return response()->json(['errors' => $validatedData->errors()], 422);
        }

        // $today = Carbon::today()->toDateString();
        $check_exist = Data::where('nopol', $request->nopol)
            ->whereDate('tanggal', $request->tanggal)->exists();

        if ($check_exist) {
            return response()->json([
                'status' => false,
                'message' => 'This nomor polisi has already been inputted today.',
            ], 422);
        }
        $tanggal_update = Carbon::now();
        $request->merge([
            'status_sj' => 'Belum selesai',
            'tanggal_update_sj' => $tanggal_update
        ]);
        $data = Data::create($request->all());
        return response()->json($data, 201);
    }

    // Get a specific data record
    public function show($id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found']);
        }
        return response()->json($data);
    }

    // Update a data record
    public function update(Request $request, $id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found']);
        }

        $validatedData = Validator::make($request->all(), [
            'tanggal' => 'sometimes|date',
            'nopol' => 'sometimes|string',
            'driver' => 'sometimes|string',
            'origin' => 'sometimes|string',
            'destinasi' => 'sometimes|string',
            'uj' => 'sometimes|numeric',
            'harga' => 'sometimes|numeric',
            'status' => 'sometimes|string',
            'status_sj' => 'sometimes|string'
        ]);

        if ($validatedData->fails()) {
            return response()->json(['errors' => $validatedData->errors()], 422);
        }

        $tanggal_update = Carbon::now();
        if ($request->status_sj) {
            $request->merge(['tanggal_update_sj' => $tanggal_update]);
        }
        $data->update($request->all());
        return response()->json($data, 200);
    }

    // Delete a data record
    public function destroy($id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found']);
        }
        $data->delete();
        return response()->json(['message' => 'Data record deleted successfully']);
    }

    public function setLunas($id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found']);
        }

        $data->update(['status' => 'confirmed']);
        return response()->json(['data' => $data]);
    }

    public function pinVerified(Request $request)
    {
        $key = env('PIN_CORRECT');
        $key_admin = env('PIN_ADMIN');

        if ($request->input('pin') === $key) {
            // Generate a verification token
            $verificationToken = base64_encode('verified_' . now());

            return response()->json([
                'success' => true,
                'verification_token' => $verificationToken,
                'role' => 'Super'
            ]);
        } elseif ($request->input('pin') === $key_admin) {
            $verificationToken = base64_encode('verified_' . now());

            return response()->json([
                'success' => true,
                'verification_token' => $verificationToken,
                'role' => 'Admin'
            ]);
        } else {
            return response()->json(['success' => false, 'message' => 'Invalid PIN']);
        }
    }

    public function lockscreen(Request $request)
    {
        // Invalidate verification on client-side by simply removing the token
        return response()->json(['success' => true, 'message' => 'Locked']);
    }
}
