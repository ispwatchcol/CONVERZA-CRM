<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MetricsController extends Controller
{
    public function index()
    {
        // Messages per day (last 30 days)
        $messagesChart = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $messagesChart[] = [
                'date' => $date->format('M d'),
                'sent' => Message::whereDate('created_at', $date)->where('status', 'sent')->count(),
                'received' => Message::whereDate('created_at', $date)->where('status', 'received')->count(),
            ];
        }

        // Contacts growth (last 12 weeks)
        $contactsGrowth = [];
        for ($i = 11; $i >= 0; $i--) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $weekEnd = now()->subWeeks($i)->endOfWeek();
            $contactsGrowth[] = [
                'week' => $weekStart->format('M d'),
                'count' => Contact::whereBetween('created_at', [$weekStart, $weekEnd])->count(),
            ];
        }

        return Inertia::render('Metrics/Index', [
            'stats' => [
                'totalContacts' => Contact::count(),
                'totalConversations' => Conversation::count(),
                'totalMessages' => Message::count(),
                'openConversations' => Conversation::where('status', 'open')->count(),
                'closedConversations' => Conversation::where('status', 'closed')->count(),
                'avgMessagesPerConversation' => Conversation::count() > 0
                    ? round(Message::count() / Conversation::count(), 1)
                    : 0,
            ],
            'messagesChart' => $messagesChart,
            'contactsGrowth' => $contactsGrowth,
        ]);
    }
}
