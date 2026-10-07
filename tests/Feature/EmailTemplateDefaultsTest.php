<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMail;
use App\Mail\SignupOtpMail;
use App\Models\Company;
use App\Models\EmailTemplate;
use App\Services\EmailTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\PortableMigrations;
use Tests\TestCase;

/**
 * The stock email designs: copied into each company to edit, and used when a
 * company has no active template of its own.
 */
class EmailTemplateDefaultsTest extends TestCase
{
    use PortableMigrations, RefreshDatabase {
        PortableMigrations::migrateFreshUsing insteadof RefreshDatabase;
    }

    protected function company(): Company
    {
        return Company::create([
            'uuid' => Str::uuid(),
            'company_name' => 'Northwind Logistics',
            'status' => true,
        ]);
    }

    protected function service(): EmailTemplateService
    {
        return app(EmailTemplateService::class);
    }

    public function test_every_stock_design_is_installed_once(): void
    {
        $company = $this->company();

        $this->service()->installDefaults($company);
        $this->service()->installDefaults($company);

        $types = EmailTemplate::forCompany($company->id)->pluck('type')->sort()->values()->all();

        $this->assertSame(['carrier_connect', 'carrier_report', 'invitation', 'login_otp'], $types);

        $template = EmailTemplate::forCompany($company->id)->where('type', 'login_otp')->first();

        $this->assertTrue($template->is_default);
        $this->assertStringContainsString($this->service()->logoUrl(), $template->body_html);
        $this->assertStringContainsString('{{otp}}', $template->body_html);
    }

    public function test_installing_leaves_a_companys_own_template_alone(): void
    {
        $company = $this->company();

        EmailTemplate::create([
            'uuid' => Str::uuid(),
            'company_id' => $company->id,
            'name' => 'Ours',
            'type' => 'invitation',
            'subject' => 'Welcome',
            'body_html' => '<p>Our own</p>',
        ]);

        $this->service()->installDefaults($company);

        $invitations = EmailTemplate::forCompany($company->id)->where('type', 'invitation')->get();

        $this->assertCount(1, $invitations);
        $this->assertSame('<p>Our own</p>', $invitations->first()->body_html);
    }

    public function test_an_edited_template_is_what_gets_sent(): void
    {
        $company = $this->company();
        $this->service()->installDefaults($company);

        EmailTemplate::forCompany($company->id)->where('type', 'login_otp')->update([
            'subject' => 'Code for {{first_name}}',
            'body_html' => '<p>Your code is {{otp}}</p>',
        ]);

        $html = (new LoginOtpMail('482913', $company->id, 'Nina'))->render();

        $this->assertStringContainsString('<p>Your code is 482913</p>', $html);
    }

    public function test_the_stock_design_is_used_when_the_company_template_is_off(): void
    {
        $company = $this->company();
        $this->service()->installDefaults($company);

        EmailTemplate::forCompany($company->id)->update(['is_active' => false]);

        $rendered = $this->service()->resolve($company->id, 'carrier_connect', [
            'company_name' => 'Northwind Logistics',
            'carrier_name' => 'Powell Distributing',
            'connect_url' => 'https://example.test/connect/abc',
        ]);

        $this->assertSame('Northwind Logistics would like to connect with you on DollarTraq', $rendered['subject']);
        $this->assertStringContainsString('href="https://example.test/connect/abc"', $rendered['body_html']);
        $this->assertStringContainsString('Complete Onboarding', $rendered['body_html']);
    }

    public function test_values_cannot_add_markup_to_the_body(): void
    {
        $rendered = $this->service()->resolve(null, 'carrier_report', [
            'carrier_name' => '<script>alert(1)</script>',
        ]);

        $this->assertStringNotContainsString('<script>', $rendered['body_html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['body_html']);
    }

    public function test_signup_uses_the_stock_verification_design(): void
    {
        $html = (new SignupOtpMail('123456', 10))->render();

        $this->assertStringContainsString('Verify Your Email', $html);
        $this->assertStringContainsString('123456', $html);
        $this->assertStringContainsString('expires in 10 minutes', $html);
    }

    public function test_the_carrier_portal_login_keeps_its_own_design(): void
    {
        $html = (new LoginOtpMail('654321'))->render();

        $this->assertStringContainsString('Security Verification', $html);
        $this->assertStringNotContainsString('Broker Dashboard', $html);
    }
}
