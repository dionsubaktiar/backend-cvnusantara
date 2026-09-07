<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Data;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class DataController extends Controller
{
    /**
     * Get distinct available year-month periods that contain data.
     */
    public function periods()
    {
        $periods = Data::whereNotNull('tanggal')
            ->selectRaw("
                DATE_FORMAT(tanggal, '%Y-%m') as period,
                DATE_FORMAT(tanggal, '%M %Y') as label,
                YEAR(tanggal) as year,
                MONTH(tanggal) as month,
                COUNT(*) as count
            ")
            ->groupByRaw("DATE_FORMAT(tanggal, '%Y-%m'), DATE_FORMAT(tanggal, '%M %Y'), YEAR(tanggal), MONTH(tanggal)")
            ->orderByRaw("DATE_FORMAT(tanggal, '%Y-%m') DESC")
            ->get();

        return response()->json([
            'status' => 'success',
            'periods' => $periods,
        ]);
    }

    /**
     * Get data records with optional month/year period filtering, search, and pagination.
     */
    public function index(Request $request)
    {
        $year = $request->input('year');
        $month = $request->input('month');

        if ($request->filled('period')) {
            $parts = explode('-', $request->input('period'));
            if (count($parts) === 2) {
                $year = (int)$parts[0];
                $month = (int)$parts[1];
            }
        }

        // Build base query
        $query = Data::whereNotNull('tanggal');

        // Apply month and year period filter if provided
        if ($year && $month) {
            $query->whereYear('tanggal', $year)
                  ->whereMonth('tanggal', $month);
        } elseif ($year) {
            $query->whereYear('tanggal', $year);
        }

        // Search filter across nopol, driver, origin, destinasi
        if ($request->filled('search')) {
            $search = trim($request->input('search'));
            $query->where(function ($q) use ($search) {
                $q->where('nopol', 'like', "%{$search}%")
                  ->orWhere('driver', 'like', "%{$search}%")
                  ->orWhere('origin', 'like', "%{$search}%")
                  ->orWhere('destinasi', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        // Status Surat Jalan filter
        if ($request->filled('status_sj')) {
            $query->where('status_sj', $request->input('status_sj'));
        }

        // Sorting
        $allowedSortColumns = ['tanggal', 'nopol', 'driver', 'status', 'status_sj', 'harga', 'uj', 'created_at'];
        $sortBy = in_array($request->input('sort_by'), $allowedSortColumns) ? $request->input('sort_by') : 'tanggal';
        $sortDirection = strtolower($request->input('sort_direction')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy($sortBy, $sortDirection)->orderBy('id', 'desc');

        // Check if caller requests legacy all-grouped data (or no period and no pagination requested)
        $hasPeriod = !empty($year) && !empty($month);
        $hasPagination = $request->has('page') || $request->has('per_page');

        if (!$hasPeriod && !$hasPagination && $request->boolean('legacy', false)) {
            // Legacy fallback: retrieve all data and group by month and year
            $data = $query->get()->groupBy(function ($item) {
                return Carbon::parse($item->tanggal)->format('F Y');
            });

            $results = [];
            foreach ($data as $monthYear => $items) {
                $results[$monthYear] = [
                    'count' => $items->count(),
                    'data' => $items->map(function ($item) {
                        return [
                            'id' => $item->id,
                            'nopol' => $item->nopol,
                            'driver' => $item->driver,
                            'origin' => $item->origin,
                            'destinasi' => $item->destinasi,
                            'tanggal' => $item->tanggal,
                            'status' => $item->status,
                            'status_sj' => $item->status_sj,
                            'tanggal_update_sj' => $item->tanggal_update_sj,
                            'harga' => $item->harga,
                            'uj' => $item->uj,
                            'foto' => $item->foto
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

        // Handling pagination
        $perPage = $request->input('per_page', 15);

        if ($perPage === 'all' || (int)$perPage <= 0) {
            $items = $query->get();
            $total = $items->count();

            $paginatedData = [
                'data' => $items,
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => $total,
                'total' => $total,
                'from' => $total > 0 ? 1 : 0,
                'to' => $total,
            ];
        } else {
            $paginator = $query->paginate((int)$perPage);
            $paginatedData = [
                'data' => $paginator->items(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem() ?? 0,
                'to' => $paginator->lastItem() ?? 0,
            ];
        }

        $activePeriodKey = ($year && $month) ? Carbon::createFromDate($year, $month, 1)->format('F Y') : null;

        return response()->json([
            'status' => 'success',
            'message' => 'Data retrieved successfully',
            'data' => $paginatedData['data'],
            'pagination' => [
                'current_page' => $paginatedData['current_page'],
                'last_page' => $paginatedData['last_page'],
                'per_page' => $paginatedData['per_page'],
                'total' => $paginatedData['total'],
                'from' => $paginatedData['from'],
                'to' => $paginatedData['to'],
            ],
            'period' => [
                'year' => $year ? (int)$year : null,
                'month' => $month ? (int)$month : null,
                'label' => $activePeriodKey,
            ],
            // Backward-compatible structure if consumer looks for dataByMonth
            'dataByMonth' => $activePeriodKey ? [
                $activePeriodKey => [
                    'count' => $paginatedData['total'],
                    'data' => $paginatedData['data'],
                ]
            ] : null,
        ]);
    }

    /**
     * Sum data grouped by month and year, optimized using direct SQL aggregations.
     */
    public function sum(Request $request)
    {
        $year = $request->input('year');
        $month = $request->input('month');

        if ($request->filled('period')) {
            $parts = explode('-', $request->input('period'));
            if (count($parts) === 2) {
                $year = (int)$parts[0];
                $month = (int)$parts[1];
            }
        }

        // If specific month and year are requested, execute 1 fast aggregation query
        if ($year && $month) {
            $monthName = Carbon::createFromDate((int)$year, (int)$month, 1)->format('F');
            $monthYear = sprintf('%s-%02d', $year, $month);
            $key = "{$monthName} {$year}";

            $summary = Data::whereYear('tanggal', $year)
                ->whereMonth('tanggal', $month)
                ->selectRaw("
                    COUNT(*) as total,
                    COALESCE(COUNT(CASE WHEN status = 'confirmed' THEN 1 END), 0) as countSukses,
                    COALESCE(COUNT(CASE WHEN status = 'pending' THEN 1 END), 0) as countPending,
                    COALESCE(COUNT(CASE WHEN status = 'canceled' THEN 1 END), 0) as countGagal,
                    COALESCE(SUM(CASE WHEN status = 'confirmed' THEN (harga - uj) ELSE 0 END), 0) as marginSum
                ")
                ->first();

            $marginSum = (int)($summary->marginSum ?? 0);
            $untungrugi = $marginSum < 0 ? 'RUGI' : 'UNTUNG';

            $resultItem = [
                'monthYear' => $monthYear,
                'untungrugi' => $untungrugi,
                'marginSum' => $marginSum,
                'countSukses' => (int)($summary->countSukses ?? 0),
                'countPending' => (int)($summary->countPending ?? 0),
                'countGagal' => (int)($summary->countGagal ?? 0),
                'total' => (int)($summary->total ?? 0),
            ];

            return response()->json([
                'status' => 'success',
                'summary' => $resultItem,
                'dataByMonthYear' => [
                    $key => $resultItem,
                ],
            ]);
        }

        // If no specific month/year, execute a single GROUP BY query across all months
        $rows = Data::whereNotNull('tanggal')
            ->selectRaw("
                DATE_FORMAT(tanggal, '%Y-%m') as monthYear,
                DATE_FORMAT(tanggal, '%M %Y') as monthNameYear,
                COUNT(*) as total,
                COALESCE(COUNT(CASE WHEN status = 'confirmed' THEN 1 END), 0) as countSukses,
                COALESCE(COUNT(CASE WHEN status = 'pending' THEN 1 END), 0) as countPending,
                COALESCE(COUNT(CASE WHEN status = 'canceled' THEN 1 END), 0) as countGagal,
                COALESCE(SUM(CASE WHEN status = 'confirmed' THEN (harga - uj) ELSE 0 END), 0) as marginSum
            ")
            ->groupByRaw("DATE_FORMAT(tanggal, '%Y-%m'), DATE_FORMAT(tanggal, '%M %Y')")
            ->orderByRaw("DATE_FORMAT(tanggal, '%Y-%m') DESC")
            ->get();

        $results = [];
        foreach ($rows as $row) {
            $marginSum = (int)$row->marginSum;
            $results[$row->monthNameYear] = [
                'monthYear' => $row->monthYear,
                'untungrugi' => $marginSum < 0 ? 'RUGI' : 'UNTUNG',
                'marginSum' => $marginSum,
                'countSukses' => (int)$row->countSukses,
                'countPending' => (int)$row->countPending,
                'countGagal' => (int)$row->countGagal,
                'total' => (int)$row->total,
            ];
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
            'driver' => 'string|nullable',
            'origin' => 'required|string',
            'destinasi' => 'required|string',
            'uj' => 'required|numeric',
            'harga' => 'required|numeric',
            'status' => 'required|string',
        ]);

        if ($validatedData->fails()) {
            return response()->json(['errors' => $validatedData->errors()], 422);
        }

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
            return response()->json(['message' => 'Data not found'], 404);
        }
        return response()->json($data);
    }

    public function update(Request $request, $id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found'], 404);
        }

        // Validate input fields and file upload
        $validatedData = Validator::make($request->all(), [
            'tanggal' => 'sometimes|date',
            'nopol' => 'sometimes|string',
            'driver' => 'sometimes|string|nullable',
            'origin' => 'sometimes|string',
            'destinasi' => 'sometimes|string',
            'uj' => 'sometimes|numeric',
            'harga' => 'sometimes|numeric',
            'status' => 'sometimes|string',
            'status_sj' => 'sometimes|string',
        ]);

        if ($validatedData->fails()) {
            return response()->json(['errors' => $validatedData->errors()], 422);
        }

        // Update `tanggal_update_sj` if `status_sj` is provided
        if ($request->status_sj) {
            $data->tanggal_update_sj = now();
        }

        // Handle file upload
        if ($request->hasFile('foto')) {
            // Delete old file if exists
            if ($data->foto) {
                Storage::disk('public')->delete($data->foto);
            }

            // Store new file
            $path = $request->file('foto')->store('uploads', 'public');
            $data->foto = $path;
        }

        $data->update($request->except('foto'));

        return response()->json([
            'message' => 'Data updated successfully',
            'data' => $data,
            'photo_url' => $data->foto ? asset("storage/{$data->foto}") : null,
        ], 200);
    }

    // Delete a data record
    public function destroy($id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found'], 404);
        }
        $data->delete();
        return response()->json(['message' => 'Data record deleted successfully']);
    }

    public function setLunas($id)
    {
        $data = Data::find($id);
        if (!$data) {
            return response()->json(['message' => 'Data not found'], 404);
        }

        $data->update(['status' => 'confirmed']);
        return response()->json(['data' => $data]);
    }

    public function pinVerified(Request $request)
    {
        $acc = Account::where('pin', '=', $request->pin)->first();

        if ($acc) {
            if ($acc->role == 'Super' || $acc->role == 'Admin') {
                $verificationToken = base64_encode('verified_' . now());

                return response()->json([
                    'success' => true,
                    'verification_token' => $verificationToken,
                    'role' => $acc->role
                ]);
            } else {
                return response()->json(['success' => false, 'message' => 'Invalid role']);
            }
        } else {
            return response()->json(['success' => false, 'message' => 'Invalid PIN']);
        }
    }

    public function lockscreen(Request $request)
    {
        return response()->json(['success' => true, 'message' => 'Locked']);
    }

    public function recapData(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'driver' => 'string|nullable',
            'origin' => 'string|nullable',
            'nopol' => 'string|nullable',
            'tanggal_start' => 'date|nullable',
            'tanggal_end' => 'date|nullable',
            'page' => 'integer|nullable',
            'per_page' => 'string|nullable',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $query = Data::query();

        if ($request->filled('driver')) {
            $query->where('driver', 'like', '%' . $request->input('driver') . '%');
        }

        if ($request->filled('origin')) {
            $query->where('origin', 'like', '%' . $request->input('origin') . '%');
        }

        if ($request->filled('nopol')) {
            $query->where('nopol', 'like', $request->input('nopol') . '%');
        }

        if ($request->filled('tanggal_start') && $request->filled('tanggal_end')) {
            $query->whereBetween('tanggal', [$request->input('tanggal_start'), $request->input('tanggal_end')]);
        } elseif ($request->filled('tanggal_start')) {
            $query->whereDate('tanggal', '>=', $request->input('tanggal_start'));
        } elseif ($request->filled('tanggal_end')) {
            $query->whereDate('tanggal', '<=', $request->input('tanggal_end'));
        }

        $query->orderBy('tanggal', 'asc');

        // If pagination requested
        if ($request->filled('per_page') && $request->input('per_page') !== 'all') {
            $perPage = (int)$request->input('per_page', 20);
            return response()->json($query->paginate($perPage));
        }

        $data = $query->get();
        return response()->json($data);
    }
}
