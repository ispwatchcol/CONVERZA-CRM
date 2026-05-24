<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\StaffMember;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index()
    {
        $totalContacts = Contact::count();
        $activeConversations = Conversation::where('status', 'open')->count();
        $messagesToday = Message::whereDate('created_at', today())->count();
        $totalStaff = StaffMember::where('is_active', true)->count();

        $recentConversations = Conversation::with(['contact', 'latestMessage'])
            ->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get()
            ->map(fn($conv) => [
                'id' => $conv->id,
                'contact_name' => $conv->contact->name ?: $conv->contact->phone,
                'contact_phone' => $conv->contact->phone,
                'status' => $conv->status,
                'last_message' => $conv->latestMessage?->body,
                'last_message_at' => $conv->latestMessage?->created_at?->diffForHumans(),
                'updated_at' => $conv->updated_at->diffForHumans(),
            ]);

        // Messages per day (last 7 days)
        $messagesChart = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $messagesChart[] = [
                'date' => $date->format('D'),
                'sent' => Message::whereDate('created_at', $date)->where('status', 'sent')->count(),
                'received' => Message::whereDate('created_at', $date)->where('status', 'received')->count(),
            ];
        }

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalContacts' => $totalContacts,
                'activeConversations' => $activeConversations,
                'messagesToday' => $messagesToday,
                'totalStaff' => $totalStaff,
            ],
            'recentConversations' => $recentConversations,
            'messagesChart' => $messagesChart,
        ]);
    }
}
