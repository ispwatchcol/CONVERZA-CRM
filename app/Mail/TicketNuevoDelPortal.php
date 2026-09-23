<?php

namespace App\Mail;

use App\Models\Brain\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Un ISP abrió un requerimiento": el aviso interno del portal de tickets.
 *
 * Trae en el asunto lo que hace falta para decidir si hay que soltar lo que uno
 * esté haciendo —cuenta y asunto— y en el cuerpo el mensaje con que lo abrió, para
 * no tener que entrar al panel solo para saber de qué se trata.
 */
class TicketNuevoDelPortal extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public string $cuenta,
        public string $mensaje,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[Soporte] {$this->cuenta} abrió el requerimiento #{$this->ticket->id}: {$this->ticket->subject}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.ticket-nuevo-del-portal',
            with: [
                'numero'   => $this->ticket->id,
                'asunto'   => $this->ticket->subject,
                'cuenta'   => $this->cuenta,
                'mensaje'  => $this->mensaje,
                'producto' => $this->ticket->product,
                'tipo'     => $this->ticket->category,
                'enlace'   => route('brain.tickets.index'),
            ],
        );
    }
}
