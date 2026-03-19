<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Message;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class SendTenantWelcomeEmail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public string $queue = 'emails';

    public function __construct(
        private readonly Tenant $tenant,
        private readonly User   $adminUser,
    ) {}

    public function handle(): void
    {
        $loginUrl  = "https://{$this->tenant->slug}." . config('app.base_domain') . '/auth/login';
        $docsUrl   = config('app.docs_url', 'https://docs.kutubxona.uz');
        $supportEmail = config('mail.from.address');

        $subject = "Welcome to Kutubxona.uz — Your Library is Ready!";

        $htmlContent = $this->buildEmailHtml($loginUrl, $docsUrl, $supportEmail);

        Mail::html($htmlContent, function (Message $message) use ($subject): void {
            $message->to($this->adminUser->email, $this->adminUser->name)
                    ->subject($subject)
                    ->from(
                        config('mail.from.address'),
                        config('mail.from.name', 'Kutubxona.uz')
                    );
        });

        Log::info('Tenant welcome email sent', [
            'tenant_id' => $this->tenant->id,
            'email'     => $this->adminUser->email,
        ]);
    }

    private function buildEmailHtml(string $loginUrl, string $docsUrl, string $supportEmail): string
    {
        $tenantName = htmlspecialchars($this->tenant->name);
        $adminName  = htmlspecialchars($this->adminUser->name);
        $trialDays  = $this->tenant->trial_ends_at
            ? now()->diffInDays($this->tenant->trial_ends_at)
            : 14;

        return <<<HTML
<!DOCTYPE html>
<html lang="uz">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Welcome to Kutubxona.uz</title>
</head>
<body style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f5f5f5; padding: 20px;">
  <div style="background: #ffffff; border-radius: 8px; padding: 40px; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">

    <div style="text-align: center; margin-bottom: 30px;">
      <h1 style="color: #2563eb; font-size: 28px; margin: 0;">📚 Kutubxona.uz</h1>
      <p style="color: #6b7280; margin-top: 8px;">Digital Library Platform</p>
    </div>

    <h2 style="color: #1f2937; font-size: 22px;">Xush kelibsiz, {$adminName}!</h2>

    <p style="color: #374151; line-height: 1.6;">
      <strong>{$tenantName}</strong> kutubxonangiz muvaffaqiyatli yaratildi.
      Siz hozir <strong>{$trialDays} kunlik</strong> bepul sinov davridan foydalanmoqdasiz.
    </p>

    <div style="background: #eff6ff; border-left: 4px solid #2563eb; padding: 16px; border-radius: 4px; margin: 24px 0;">
      <h3 style="color: #1e40af; margin: 0 0 12px;">Kutubxonangizga kirish:</h3>
      <a href="{$loginUrl}" style="background: #2563eb; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; display: inline-block; font-weight: bold;">
        Kirish →
      </a>
    </div>

    <h3 style="color: #1f2937;">Boshlash uchun qadamlar:</h3>
    <ol style="color: #374151; line-height: 2;">
      <li>Admin panelga kiring</li>
      <li>Mualliflar va nashriyotlarni qo'shing</li>
      <li>Kitoblarni yuklang (PDF, EPUB)</li>
      <li>Foydalanuvchilarni taklif qiling</li>
    </ol>

    <div style="border-top: 1px solid #e5e7eb; margin-top: 32px; padding-top: 20px;">
      <p style="color: #6b7280; font-size: 14px;">
        Savollaringiz bo'lsa, bizga yozing: <a href="mailto:{$supportEmail}">{$supportEmail}</a>
      </p>
      <p style="color: #6b7280; font-size: 14px;">
        <a href="{$docsUrl}">Dokumentatsiya</a>
      </p>
    </div>

  </div>
</body>
</html>
HTML;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendTenantWelcomeEmail permanently failed', [
            'tenant_id' => $this->tenant->id,
            'email'     => $this->adminUser->email,
            'error'     => $exception->getMessage(),
        ]);
    }
}
