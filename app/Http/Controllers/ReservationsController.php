<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use App\Models\Equipment;
use App\Models\Reservation;
use App\Models\ReservationCowork;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Illuminate\Support\Str;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;
use App\Mail\ReservationApprovedMail;
use App\Mail\ReservationCanceledMail;
use App\Mail\ReservationCreatedAdminMail;

class ReservationsController extends Controller
{
    public function index(Request $request)
    {

        if ($request->has('export') && $request->get('export') === 'true') {
            return $this->exportData($request);
        }
        // Base reservations
        $reservations = DB::table('reservations as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.*', 'u.name as user_name')
            ->orderByDesc('r.created_at')
            ->get();

        // Supplementary data keyed by reservation_id
        $placeByReservation = [];
        if (Schema::hasTable('reservation_places') && Schema::hasTable('places')) {
            DB::table('reservation_places as rp')
                ->leftJoin('places as p', 'p.id', '=', 'rp.places_id')
                ->select('rp.reservation_id', 'p.name as place_name', 'p.place_type')
                ->orderByDesc('rp.id')
                ->get()
                ->each(function ($row) use (&$placeByReservation) {
                    $placeByReservation[$row->reservation_id] = [
                        'place_name' => $row->place_name,
                        'place_type' => $row->place_type,
                    ];
                });
        }

        $equipmentsByReservation = [];
        if (Schema::hasTable('reservation_equipment') && Schema::hasTable('equipment')) {
            $hasEquipmentImage = Schema::hasTable('equipment') && Schema::hasColumn('equipment', 'image');
            $query = DB::table('reservation_equipment as re')
                ->leftJoin('equipment as e', 'e.id', '=', 're.equipment_id')
                ->select('re.reservation_id', 'e.id as equipment_id', 'e.reference', 'e.mark');
            if ($hasEquipmentImage) {
                $query->addSelect('e.image');
            }
            $rows = $query->get();
            foreach ($rows as $row) {
                $img = isset($row->image) ? $row->image : null;
                if ($img) {
                    if (!Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'storage/img/equipment/'.ltrim($img, '/');
                    }
                    $img = asset($img);
                }
                $equipmentsByReservation[$row->reservation_id] = $equipmentsByReservation[$row->reservation_id] ?? [];
                $equipmentsByReservation[$row->reservation_id][] = [
                    'id' => $row->equipment_id,
                    'reference' => $row->reference,
                    'mark' => $row->mark,
                    'image' => $img,
                ];
            }
        }

        $teamsByReservation = [];
        if (Schema::hasTable('reservation_teams') && Schema::hasTable('users')) {
            // In this schema, reservation_teams links users directly to reservations
            $userImageColumn = Schema::hasColumn('users', 'image') ? 'image' : (Schema::hasColumn('users', 'profile_photo_path') ? 'profile_photo_path' : null);
            $memberQuery = DB::table('reservation_teams as rt')
                ->leftJoin('users as u', 'u.id', '=', 'rt.user_id')
                ->select('rt.reservation_id', 'u.id as user_id', 'u.name');
            if ($userImageColumn) {
                $memberQuery->addSelect('u.' . $userImageColumn . ' as image');
            }
            $members = $memberQuery->get();
            foreach ($members as $m) {
                $uimg = isset($m->image) ? $m->image : null;
                if ($uimg) {
                    if (!Str::startsWith($uimg, ['http://', 'https://', 'storage/'])) {
                        $uimg = 'storage/img/profile/'.ltrim($uimg, '/');
                    }
                    $uimg = asset($uimg);
                }
                $teamsByReservation[$m->reservation_id] = $teamsByReservation[$m->reservation_id] ?? [ 'team_name' => null, 'members' => [] ];
                $teamsByReservation[$m->reservation_id]['members'][] = [
                    'id' => $m->user_id,
                    'name' => $m->name,
                    'image' => $uimg,
                ];
            }
        }

        // Studio name by reservation (for base list where type=studio)
        $studioByReservation = [];
        if (Schema::hasTable('reservations') && Schema::hasTable('studios')) {
            DB::table('reservations as r')
                ->leftJoin('studios as s', 's.id', '=', 'r.studio_id')
                ->where('r.type', 'studio')
                ->select('r.id as reservation_id', 's.name as studio_name')
                ->get()
                ->each(function ($row) use (&$studioByReservation) {
                    $studioByReservation[$row->reservation_id] = $row->studio_name;
                });
        }

        $enriched = $reservations->map(function ($r) use ($placeByReservation, $equipmentsByReservation, $teamsByReservation) {
            $place = $placeByReservation[$r->id] ?? null;
            $team = $teamsByReservation[$r->id] ?? null;
            return [
                'id' => $r->id,
                'user_name' => $r->user_name,
                'date' => $r->date ?? $r->day ?? null,
                'start' => $r->start ?? null,
                'end' => $r->end ?? null,
                'type' => $r->type ?? null,
                'title' => $r->title ?? null,
                'description' => $r->description ?? null,
                'approved' => (bool) ($r->approved ?? 0),
                'start_signed' => (bool) ($r->start_signed ?? 0),
                'end_signed' => (bool) ($r->end_signed ?? 0),
                'canceled' => (bool) ($r->canceled ?? 0),
                'passed' => (bool) ($r->passed ?? 0),
                'place_name' => $place['place_name'] ?? null,
                'place_type' => $place['place_type'] ?? null,
                'equipments' => $equipmentsByReservation[$r->id] ?? [],
                'team_name' => $team['team_name'] ?? null,
                'team_members' => $team['members'] ?? [],
            ];
        });

        // Attach studio_name for studio reservations
        if (!empty($studioByReservation)) {
            $enriched = $enriched->map(function ($row) use ($studioByReservation) {
                if (($row['type'] ?? null) === 'studio') {
                    $row['studio_name'] = $studioByReservation[$row['id']] ?? null;
                }
                return $row;
            });
        }

        // Places reservations (pivot)
        // Cowork reservations (own table)
        $coworkReservations = [];
        if (Schema::hasTable('reservation_coworks')) {
            // Auto-approve any pending cowork reservations
            DB::table('reservation_coworks')
                ->where('approved', 0)
                ->where('canceled', 0)
                ->update([
                    'approved' => 1,
                    'updated_at' => now(),
                ]);
            
            $coworkReservations = DB::table('reservation_coworks as rc')
                ->leftJoin('users as u', 'u.id', '=', 'rc.user_id')
                ->select('rc.*', 'u.name as user_name')
                ->orderByDesc('rc.created_at')
                ->get();
        }

        // Studio reservations (main reservations table with type=studio and studio_id)
        $studioReservations = [];
        if (Schema::hasTable('reservations') && Schema::hasTable('studios')) {
            $studioReservations = DB::table('reservations as r')
                ->leftJoin('studios as s', 's.id', '=', 'r.studio_id')
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.type', 'studio')
                ->select('r.*', 's.name as studio_name', 'u.name as user_name')
                ->orderByDesc('r.created_at')
                ->get();
        }

        // Meeting room reservations (type=meeting_room with meeting_room_id)
        $meetingRoomReservations = DB::table('reservation_meeting_rooms as rm')
            ->leftJoin('users as u', 'u.id', '=', 'rm.user_id')
            ->leftJoin('meeting_rooms as m', 'm.id', '=', 'rm.meeting_room_id')
            ->select('rm.*', 'u.name as user_name', 'm.name as room_name')
            ->orderByDesc('rm.created_at')
            ->get();

        return Inertia::render('admin/reservations/index', [
            'reservations' => $enriched,
            'coworkReservations' => $coworkReservations,
            'studioReservations' => $studioReservations,
            'meetingRoomReservations' => $meetingRoomReservations,
        ]);
    }

    public function approve(int $reservation)
    {
        if (!Schema::hasTable('reservations')) {
            return back()->with('error', 'Reservations table missing');
        }

        // Get the reservation details before updating
        $reservationData = DB::table('reservations')->where('id', $reservation)->first();
        if (!$reservationData) {
            return back()->with('error', 'Reservation not found');
        }

        // Get the user who made the reservation
        $user = DB::table('users')->where('id', $reservationData->user_id)->first();
        if (!$user) {
            return back()->with('error', 'User not found');
        }

        // Update the reservation
        DB::table('reservations')->where('id', $reservation)->update([
            'approved' => 1,
            'approve_id' => auth()->id(),
            'canceled' => 0,
            'updated_at' => now(),
        ]);

        // Send approval email
        try {
            Mail::to("boujjarr@gmail.com")->send(new ReservationApprovedMail($user, $reservationData));
        } catch (\Exception $e) {
            // Log the error but don't fail the approval
            \Log::error('Failed to send approval email: ' . $e->getMessage());
        }

        return back()->with('success', 'Reservation approved');
    }

    public function cancel(int $reservation)
    {
        if (!Schema::hasTable('reservations')) {
            return back()->with('error', 'Reservations table missing');
        }

        // Get the reservation details before updating
        $reservationData = DB::table('reservations')->where('id', $reservation)->first();
        if (!$reservationData) {
            return back()->with('error', 'Reservation not found');
        }

        // Get the user who made the reservation
        $user = DB::table('users')->where('id', $reservationData->user_id)->first();
        if (!$user) {
            return back()->with('error', 'User not found');
        }

        // Update the reservation
        DB::table('reservations')->where('id', $reservation)->update([
            'canceled' => 1,
            'approved' => 0,
            'updated_at' => now(),
        ]);

        // Send cancellation email
        try {
            Mail::to("boujjarr@gmail.com")->send(new ReservationCanceledMail($user, $reservationData));
        } catch (\Exception $e) {
            // Log the error but don't fail the cancellation
            \Log::error('Failed to send cancellation email: ' . $e->getMessage());
        }

        return back()->with('success', 'Reservation canceled');
    }

    public function info(int $reservation)
    {
        $result = [
            'reservation_id' => $reservation,
            'team_name' => null,
            'team_members' => [],
            'equipments' => [],
        ];

        // Team name and members with images
        if (Schema::hasTable('reservation_teams') && Schema::hasTable('teams')) {
            $team = DB::table('reservation_teams as rt')
                ->leftJoin('teams as t', 't.id', '=', 'rt.team_id')
                ->where('rt.reservation_id', $reservation)
                ->select('t.id as team_id', 't.name as team_name')
                ->first();
            if ($team) {
                $result['team_name'] = $team->team_name;
                if (Schema::hasTable('team_user') && Schema::hasTable('users')) {
                    $userImageColumn = Schema::hasColumn('users', 'image') ? 'image' : (Schema::hasColumn('users', 'profile_photo_path') ? 'profile_photo_path' : null);
                    $q = DB::table('team_user as tu')
                        ->leftJoin('users as u', 'u.id', '=', 'tu.user_id')
                        ->where('tu.team_id', $team->team_id)
                        ->select('u.id as user_id', 'u.name');
                    if ($userImageColumn) {
                        $q->addSelect('u.' . $userImageColumn . ' as image');
                    }
                    $members = $q->get()->map(function ($u) {
                        return [
                            'id' => $u->user_id,
                            'name' => $u->name,
                            'image' => isset($u->image) && $u->image ? asset($u->image) : null,
                        ];
                    })->values()->all();
                    $result['team_members'] = $members;
                }
            }
        } elseif (Schema::hasTable('reservation_teams') && Schema::hasTable('users')) {
            // Fallback schema: reservation_teams links users directly to reservations
            $userImageColumn = Schema::hasColumn('users', 'image') ? 'image' : (Schema::hasColumn('users', 'profile_photo_path') ? 'profile_photo_path' : null);
            $q = DB::table('reservation_teams as rt')
                ->leftJoin('users as u', 'u.id', '=', 'rt.user_id')
                ->where('rt.reservation_id', $reservation)
                ->select('u.id as user_id', 'u.name');
            if ($userImageColumn) {
                $q->addSelect('u.' . $userImageColumn . ' as image');
            }
            $members = $q->get()->map(function ($u) {
                $img = isset($u->image) ? $u->image : null;
                if ($img) {
                    if (!Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'storage/img/profile/'.ltrim($img, '/');
                    }
                    $img = asset($img);
                }
                return [
                    'id' => $u->user_id,
                    'name' => $u->name,
                    'image' => $img,
                ];
            })->values()->all();
            $result['team_members'] = $members;
        }

        // Equipments with image, reference, mark
        if (Schema::hasTable('reservation_equipment') && Schema::hasTable('equipment')) {
            $hasEquipmentImage = Schema::hasColumn('equipment', 'image');
            $eq = DB::table('reservation_equipment as re')
                ->leftJoin('equipment as e', 'e.id', '=', 're.equipment_id')
                ->where('re.reservation_id', $reservation)
                ->select('e.id as equipment_id', 'e.reference', 'e.mark');
            if ($hasEquipmentImage) {
                $eq->addSelect('e.image');
            }
            $result['equipments'] = $eq->get()->map(function ($e) {
                $img = isset($e->image) ? $e->image : null;
                if ($img) {
                    if (!Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                        $img = 'storage/img/equipment/'.ltrim($img, '/');
                    }
                    $img = asset($img);
                }
                return [
                    'id' => $e->equipment_id,
                    'reference' => $e->reference,
                    'mark' => $e->mark,
                    'image' => $img,
                ];
            })->values()->all();
        }

        return response()->json($result);
    }

    public function byPlace(string $type, int $id)
    {
        $reservations = [];

        if ($type === 'studio') {
            $reservations = DB::table('reservations as r')
                ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.studio_id', $id)
                ->select(
                    'r.id', 
                    'r.day as start', 
                    'r.start as startTime', 
                    'r.end as endTime', 
                    'r.title', 
                    'r.approved', 
                    'r.canceled',
                    'u.name as user_name'
                )
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'title' => $r->title . ' — ' . $r->user_name,
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                    ];
                });
        }

        if ($type === 'cowork') {
            $reservations = DB::table('reservation_coworks as rc')
                ->leftJoin('users as u', 'u.id', '=', 'rc.user_id')
                ->leftJoin('coworks as c', 'c.id', '=', 'rc.table')
                ->where('rc.table', $id)
                ->select(
                    'rc.id', 
                    'rc.day as start', 
                    'rc.start as startTime', 
                    'rc.end as endTime', 
                    'rc.approved', 
                    'rc.canceled',
                    'u.name as user_name',
                    'c.table as table_number'
                )
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'title' => 'Table ' . $r->table_number . ' — ' . $r->user_name,
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                    ];
                });
        }

        if ($type === 'meeting_room') {
            $reservations = DB::table('reservation_meeting_rooms as rmr')
                ->leftJoin('users as u', 'u.id', '=', 'rmr.user_id')
                ->where('rmr.meeting_room_id', $id)
                ->select(
                    'rmr.id', 
                    'rmr.day as start', 
                    'rmr.start as startTime', 
                    'rmr.end as endTime', 
                    'rmr.approved', 
                    'rmr.canceled',
                    'u.name as user_name'
                )
                ->get()
                ->map(function ($r) {
                    return [
                        'id' => $r->id,
                        'title' => 'Meeting Room — ' . $r->user_name,
                        'start' => $r->start . 'T' . $r->startTime,
                        'end' => $r->start . 'T' . $r->endTime,
                        'backgroundColor' => $r->canceled ? '#6b7280' : ($r->approved ? '#FFC801' : '#f59e0b'),
                    ];
                });
        }

        return response()->json($reservations);
    }

    /**
     * Get all users for team member selector modal
     */
    public function getUsers()
    {
        $users = User::select('id', 'name', 'image')
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                $img = $user->image;
                if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                    $img = 'storage/img/profile/' . ltrim($img, '/');
                }
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'image' => $img ? asset($img) : null,
                ];
            });

        return response()->json($users);
    }

    /**
     * Get all equipment for equipment selector modal
     */
    public function getEquipment()
    {
        $equipment = Equipment::with('equipmentType')
            ->where('state', 1) // Only available equipment
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($e) {
                $img = $e->image;
                if ($img && !Str::startsWith($img, ['http://', 'https://', 'storage/'])) {
                    $img = 'storage/img/equipment/' . ltrim($img, '/');
                }
                return [
                    'id' => $e->id,
                    'reference' => $e->reference,
                    'mark' => $e->mark,
                    'type' => $e->equipmentType->name ?? 'other',
                    'image' => $img ? asset($img) : null,
                ];
            });

        return response()->json($equipment);
    }

    /**
     * Generate PDF using the reservation_combined.blade view
     */
    public function generatePdf(int $reservation)
    {
        try {
            $reservationData = $this->getReservationDetails($reservation);
            if (!$reservationData) {
                return response()->json(['error' => 'Reservation not found'], 404);
            }

            $pdf = Pdf::loadView('pdf.reservation_combined', ['reservation' => $reservationData])
                ->setPaper('a4', 'portrait');

            $userName = str_replace(' ', '_', $reservationData['user_name'] ?? 'User');
            $date = $reservationData['date'] ?? date('Y-m-d');
            $filename = "Reservation_{$userName}_{$date}.pdf";

            return $pdf->download($filename);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'PDF generation failed', 'message' => $e->getMessage()], 500);
        }
    }

    private function getReservationDetails(int $reservationId): ?array
    {
        $reservation = DB::table('reservations as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.id', $reservationId)
            ->select('r.*', 'u.name as user_name')
            ->first();

        if (!$reservation) {
            return null;
        }

        $equipments = [];
        if (Schema::hasTable('reservation_equipment') && Schema::hasTable('equipment')) {
            $equipments = DB::table('reservation_equipment as re')
                ->leftJoin('equipment as e', 'e.id', '=', 're.equipment_id')
                ->where('re.reservation_id', $reservationId)
                ->select('e.id', 'e.reference', 'e.mark', 'e.image')
                ->get()
                ->map(function ($e) {
                    return [
                        'id' => $e->id,
                        'reference' => $e->reference,
                        'mark' => $e->mark,
                        'image' => $e->image,
                    ];
                })
                ->toArray();
        }

        $teamMembers = [];
        if (Schema::hasTable('reservation_teams') && Schema::hasTable('users')) {
            $teamMembers = DB::table('reservation_teams as rt')
                ->leftJoin('users as u', 'u.id', '=', 'rt.user_id')
                ->where('rt.reservation_id', $reservationId)
                ->select('u.id', 'u.name', 'u.image')
                ->get()
                ->map(function ($u) {
                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'image' => $u->image,
                    ];
                })
                ->toArray();
        }

        // Get approver name by looking up the user with approve_id
        $approverName = null;
        if ($reservation->approve_id) {
            $approver = DB::table('users')->where('id', $reservation->approve_id)->first();
            $approverName = $approver ? $approver->name : null;
        }

        return [
            'id' => $reservation->id,
            'user_name' => $reservation->user_name,
            'date' => $reservation->date ?? $reservation->day,
            'start' => $reservation->start,
            'end' => $reservation->end,
            'title' => $reservation->title,
            'description' => $reservation->description,
            'approved' => (bool) ($reservation->approved ?? 0),
            'approver_name' => $approverName,
            'equipments' => $equipments,
            'team_members' => $teamMembers,
        ];
    }

    /**
     * Store new reservation with teams and equipment
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'studio_id' => 'required|integer|exists:studios,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'day' => 'required|date',
            'start' => 'required|string',
            'end' => 'required|string',
            'team_members' => 'nullable|array',
            'team_members.*' => 'integer|exists:users,id',
            'equipment' => 'nullable|array',
            'equipment.*' => 'integer|exists:equipment,id',
        ]);

        try {
            $reservationId = null;

            DB::transaction(function () use ($validated, &$reservationId) {
                $lastId = (int) (DB::table('reservations')->max('id') ?? 0);
                $reservationId = $lastId + 1;

                DB::table('reservations')->insert([
                    'id' => $reservationId,
                    'studio_id' => $validated['studio_id'],
                    'user_id' => auth()->id(),
                    'title' => $validated['title'],
                    'description' => $validated['description'] ?? '',
                    'day' => $validated['day'],
                    'start' => $validated['start'],
                    'end' => $validated['end'],
                    'type' => 'studio',
                    'approved' => 0,
                    'canceled' => 0,
                    'passed' => 0,
                    'start_signed' => 0,
                    'end_signed' => 0,
                    'created_at' => now()->toDateTimeString(),
                    'updated_at' => now()->toDateTimeString(),
                ]);

                // Insert team members
                if (!empty($validated['team_members'])) {
                    $teamData = array_map(function ($userId) use ($reservationId) {
                        return [
                            'reservation_id' => $reservationId,
                            'user_id' => $userId,
                            'created_at' => now()->toDateTimeString(),
                            'updated_at' => now()->toDateTimeString(),
                        ];
                    }, $validated['team_members']);

                    DB::table('reservation_teams')->insert($teamData);
                }

                // Insert equipment
                if (!empty($validated['equipment'])) {
                    $equipmentData = array_map(function ($equipmentId) use ($validated, $reservationId) {
                        return [
                            'reservation_id' => $reservationId,
                            'equipment_id' => $equipmentId,
                            'day' => $validated['day'],
                            'start' => $validated['start'],
                            'end' => $validated['end'],
                            'created_at' => now()->toDateTimeString(),
                            'updated_at' => now()->toDateTimeString(),
                        ];
                    }, $validated['equipment']);

                    DB::table('reservation_equipment')->insert($equipmentData);
                }
            });

            // Send admin notification email (outside transaction)
            try {
                \Log::info('Starting admin notification email process for reservation ID: ' . $reservationId);

                // Get admin users
                $adminUsers = DB::table('users')
                    ->whereIn('role', ['admin', 'super_admin'])
                    ->select('email', 'name')
                    ->get();

                \Log::info('Admin users found: ' . $adminUsers->count());

                // Get the created reservation data for the email
                $createdReservation = DB::table('reservations')
                    ->leftJoin('studios', 'studios.id', '=', 'reservations.studio_id')
                    ->where('reservations.id', $reservationId)
                    ->select('reservations.*', 'studios.name as studio_name')
                    ->first();

                \Log::info('Reservation data: ' . json_encode($createdReservation));

                // Get the user who made the reservation
                $reservationUser = DB::table('users')->where('id', auth()->id())->first();

                \Log::info('User data: ' . json_encode($reservationUser));

                // Send email to all admin users
                foreach ($adminUsers as $admin) {
                    \Log::info('Sending email to: boujjarr@gmail.com');
                    // Mail::to($admin->email)->send(new ReservationCreatedAdminMail($reservationUser, $createdReservation));
                    Mail::to("boujjarr@gmail.com")->send(new ReservationCreatedAdminMail($reservationUser, $createdReservation));
                    \Log::info('Email sent successfully to: boujjarr@gmail.com');
                }
            } catch (\Exception $e) {
                // Log the error but don't fail the reservation creation
                \Log::error('Failed to send admin notification email: ' . $e->getMessage());
                \Log::error('Stack trace: ' . $e->getTraceAsString());
            }

            return back()->with('success', 'Reservation created successfully');
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to create reservation: ' . $e->getMessage());
        }
    }


    /**
     * Show studio calendar page
     */
    public function studioCalendar(int $studio)
    {
        $studioData = DB::table('studios')->where('id', $studio)->first();

        if (!$studioData) {
            return redirect()->route('admin.places')->with('error', 'Studio not found');
        }

        return Inertia::render('admin/places/studios/calendar', [
            'studio' => [
                'id' => $studioData->id,
                'name' => $studioData->name,
                'image' => $studioData->image ?? null,
            ],
        ]);
    }
    public function coworkCalendar(int $cowork)
    {
        $coworkData = DB::table('coworks')->where('id', $cowork)->first();

        if (!$coworkData) {
            return redirect()->route('admin.places')->with('error', 'Coworks not found');
        }

        return Inertia::render('admin/places/coworks/CoworkCalendar', [
            'cowork' => [
                'id' => $coworkData->id,
                'table' => $coworkData->table,
                'image' => $coworkData->image ?? null,
            ],
        ]);
    }

    public function storeReservationCowork(Request $request)
    {
        $request->validate([
            'table' => 'required|integer',
            'seats' => 'required|integer|min:1',
            'day' => 'required|date',
            'start' => 'required',
            'end' => 'required',
        ]);
        
        ReservationCowork::create([
            'table' => $request->table,
            'seats' => $request->seats,
            'day' => $request->day,
            'start' => $request->start,
            'end' => $request->end,
            'user_id' => Auth::id(),
            'approved' => 1,
        ]);
        
        return back()->with('success', 'Cowork reservation created successfully');
    }



    public function cancelCowork(int $reservation)
    {
        if (!Schema::hasTable('reservation_coworks')) {
            return back()->with('error', 'Reservation coworks table missing');
        }

        // Get the cowork reservation details before updating
        $reservationData = DB::table('reservation_coworks')->where('id', $reservation)->first();
        if (!$reservationData) {
            return back()->with('error', 'Cowork reservation not found');
        }

        // Get the user who made the reservation
        $user = DB::table('users')->where('id', $reservationData->user_id)->first();
        if (!$user) {
            return back()->with('error', 'User not found');
        }

        // Update the cowork reservation
        DB::table('reservation_coworks')->where('id', $reservation)->update([
            'canceled' => 1,
            'approved' => 0,
            'updated_at' => now(),
        ]);

        // Send cancellation email
        try {
            // Create a reservation-like object for the email
            $reservationForEmail = (object) [
                'id' => $reservationData->id,
                'title' => "Cowork - Table {$reservationData->table}",
                'date' => $reservationData->day,
                'start' => $reservationData->start,
                'end' => $reservationData->end,
                'description' => 'Cowork space reservation',
                'type' => 'cowork'
            ];

            Mail::to("boujjarr@gmail.com")->send(new ReservationCanceledMail($user, $reservationForEmail));
        } catch (\Exception $e) {
            // Log the error but don't fail the cancellation
            \Log::error('Failed to send cowork cancellation email: ' . $e->getMessage());
        }

        return back()->with('success', 'Cowork reservation canceled');
    }

    private function exportData(Request $request)
    {
        $requestedFields = array_filter(array_map('trim', explode(',', (string) $request->query('fields', 'user_name,date,start,end,type'))));

        if (empty($requestedFields)) {
            $requestedFields = ['user_name', 'date', 'start', 'end', 'type'];
        }

        $query = DB::table('reservations as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->select('r.*', 'u.name as user_name')
            ->orderByDesc('r.created_at');

        $needsPlaces = in_array('place_name', $requestedFields) || in_array('place_type', $requestedFields);
        $placeByReservation = [];

        if ($needsPlaces && Schema::hasTable('reservation_places') && Schema::hasTable('places')) {
            DB::table('reservation_places as rp')
                ->leftJoin('places as p', 'p.id', '=', 'rp.places_id')
                ->select('rp.reservation_id', 'p.name as place_name', 'p.place_type')
                ->get()
                ->each(function ($row) use (&$placeByReservation) {
                    $placeByReservation[$row->reservation_id] = [
                        'place_name' => $row->place_name,
                        'place_type' => $row->place_type,
                    ];
                });
        }

        $filename = 'reservations_export_' . now()->format('Y-m-d_H-i-s') . '.csv';

        $response = new StreamedResponse(function () use ($query, $requestedFields, $placeByReservation) {
            $handle = fopen('php://output', 'w');

            // Add BOM for Excel UTF-8 compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            // Use semicolon delimiter for Excel
            fputcsv($handle, $requestedFields, ';');

            $query->chunk(500, function ($reservations) use ($handle, $requestedFields, $placeByReservation) {
                foreach ($reservations as $reservation) {
                    $row = [];

                    foreach ($requestedFields as $field) {
                        $value = null;

                        if ($field === 'place_name' || $field === 'place_type') {
                            $value = $placeByReservation[$reservation->id][$field] ?? '';
                        } elseif ($field === 'approved' || $field === 'canceled' || $field === 'passed' || $field === 'start_signed' || $field === 'end_signed') {
                            $value = (isset($reservation->$field) && $reservation->$field) ? 'Yes' : 'No';
                        } elseif ($field === 'created_at' || $field === 'updated_at') {
                            $value = isset($reservation->$field) ? date('Y-m-d H:i:s', strtotime($reservation->$field)) : '';
                        } elseif ($field === 'date' && empty($reservation->date) && !empty($reservation->day)) {
                            $value = $reservation->day;
                        } else {
                            $value = $reservation->$field ?? '';
                        }

                        if (is_string($value)) {
                            $value = str_replace(["\r\n", "\n", "\r"], ' ', $value);
                        }

                        $row[] = $value;
                    }

                    // Use semicolon delimiter
                    fputcsv($handle, $row, ';');
                }
            });

            fclose($handle);
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

public function meetingRoomCalendar(int $meetingRoom)
{
    $meetingRoomData = DB::table('meeting_rooms')->where('id', $meetingRoom)->first();
    
    if (!$meetingRoomData) {
        return redirect()->route('admin.places')->with('error', 'Meeting room not found');
    }

    // Get first image for meeting room
    $image = DB::table('meeting_room_images')
        ->where('meeting_room_id', $meetingRoom)
        ->first();

    return Inertia::render('admin/places/meeting_rooms/MeetingRoomCalendar', [
        'meetingRoom' => [
            'id' => $meetingRoomData->id,
            'name' => $meetingRoomData->name,
            'state' => $meetingRoomData->state,
            'image' => $image->image ?? null,
        ],
    ]);
}

public function storeReservationMeetingRoom(Request $request)
{
    $request->validate([
        'meeting_room_id' => 'required|exists:meeting_rooms,id',
        'day' => 'required|date',
        'start' => 'required',
        'end' => 'required',
    ]);

    $lastId = (int) (DB::table('reservation_meeting_rooms')->max('id') ?? 0);
    $reservationId = $lastId + 1;

    // Get user data for email
    $user = DB::table('users')->where('id', Auth::id())->first();
    if (!$user) {
        return back()->with('error', 'User not found');
    }

    // Get meeting room name
    $meetingRoom = DB::table('meeting_rooms')->where('id', $request->meeting_room_id)->first();

    // Create meeting room reservation as auto-approved
    DB::table('reservation_meeting_rooms')->insert([
        'id' => $reservationId,
        'meeting_room_id' => $request->meeting_room_id,
        'user_id' => Auth::id(),
        'day' => $request->day,
        'start' => $request->start,
        'end' => $request->end,
        'passed' => 0,
        'approved' => 1,
        'canceled' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Send approval email for auto-approved meeting room reservation
    try {
        $reservationData = (object) [
            'id' => $reservationId,
            'title' => "Meeting Room - {$meetingRoom->name}",
            'date' => $request->day,
            'start' => $request->start,
            'end' => $request->end,
            'description' => 'Meeting room reservation',
            'type' => 'meeting_room'
        ];

        Mail::to("boujjarr@gmail.com")->send(new ReservationApprovedMail($user, $reservationData));
    } catch (\Exception $e) {
        \Log::error('Failed to send meeting room approval email: ' . $e->getMessage());
    }

    return back()->with('success', 'Meeting room reservation created and approved automatically');
}

public function cancelMeetingRoom(int $reservation)
{
    if (!Schema::hasTable('reservation_meeting_rooms')) {
        return back()->with('error', 'Reservation meeting rooms table missing');
    }

    $reservationData = DB::table('reservation_meeting_rooms')->where('id', $reservation)->first();
    if (!$reservationData) {
        return back()->with('error', 'Meeting room reservation not found');
    }

    $user = DB::table('users')->where('id', $reservationData->user_id)->first();
    if (!$user) {
        return back()->with('error', 'User not found');
    }

    $meetingRoom = DB::table('meeting_rooms')->where('id', $reservationData->meeting_room_id)->first();

    DB::table('reservation_meeting_rooms')->where('id', $reservation)->update([
        'canceled' => 1,
        'approved' => 0,
        'updated_at' => now(),
    ]);

    try {
        $reservationForEmail = (object) [
            'id' => $reservationData->id,
            'title' => "Meeting Room - {$meetingRoom->name}",
            'date' => $reservationData->day,
            'start' => $reservationData->start,
            'end' => $reservationData->end,
            'description' => 'Meeting room reservation',
            'type' => 'meeting_room'
        ];

        Mail::to("boujjarr@gmail.com")->send(new ReservationCanceledMail($user, $reservationForEmail));
    } catch (\Exception $e) {
        \Log::error('Failed to send meeting room cancellation email: ' . $e->getMessage());
    }

    return back()->with('success', 'Meeting room reservation canceled');
}



}


