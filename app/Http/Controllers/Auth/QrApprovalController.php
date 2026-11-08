<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ApproveQrLogin;
use App\Actions\Auth\ClaimQrLogin;
use App\Actions\Auth\DenyQrLogin;
use App\Exceptions\QrLoginRejected;
use App\Http\Controllers\Controller;
use App\Models\QrLogin;
use App\Models\User;
use App\Services\DeviceFingerprint;
use App\Services\QrLoginPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

class QrApprovalController extends Controller
{
    public function __construct(
        private readonly QrLoginPolicy $policy,
        private readonly DeviceFingerprint $fingerprints,
    ) {}

    /**
     * Read-only on purpose: link scanners and prefetchers open URLs, and
     * opening one must never claim, approve or redeem anything.
     */
    public function show(Request $request, string $credential): Response|RedirectResponse|BaseResponse
    {
        $qrLogin = $this->policy->find($credential);

        if ($qrLogin === null) {
            return Inertia::render('auth/QrShow', ['mode' => 'invalid'])->toResponse($request)->setStatusCode(404);
        }

        $user = $request->user();

        if ($qrLogin->direction === QrLogin::DIRECTION_A) {
            return $user instanceof User
                ? $this->showToApprover($request, $qrLogin, $credential, $user)
                : redirect()->guest(route('login'));
        }

        return $user instanceof User
            ? $this->render('owner', $request, $qrLogin)
            : $this->showToRequester($request, $qrLogin, $credential);
    }

    public function claim(Request $request, string $credential, ClaimQrLogin $claimQrLogin): RedirectResponse
    {
        $qrLogin = $this->policy->find($credential);
        abort_if($qrLogin === null, 404);

        try {
            $claimQrLogin->handle($request, $qrLogin);
        } catch (QrLoginRejected $rejected) {
            return redirect()->route('qr.show', ['credential' => $credential])->with('qr_notice', $rejected->getMessage());
        }

        return redirect()->route('qr.show', ['credential' => $credential]);
    }

    public function approve(Request $request, string $credential, ApproveQrLogin $approveQrLogin): RedirectResponse|JsonResponse
    {
        $request->validate(['number' => ['required', 'integer', 'between:10,99']]);

        $qrLogin = $this->policy->find($credential);
        abort_if($qrLogin === null, 404);

        try {
            $approveQrLogin->handle($request, $qrLogin, $request->integer('number'));
        } catch (QrLoginRejected $rejected) {
            return $this->rejected($request, $credential, $rejected);
        }

        return $request->expectsJson()
            ? response()->json(['status' => QrLogin::APPROVED])
            : redirect()->route('qr.show', ['credential' => $credential]);
    }

    public function deny(Request $request, string $credential, DenyQrLogin $denyQrLogin): RedirectResponse|JsonResponse
    {
        $qrLogin = $this->policy->find($credential);
        abort_if($qrLogin === null, 404);

        try {
            $denyQrLogin->handle($request, $qrLogin);
        } catch (QrLoginRejected $rejected) {
            return $this->rejected($request, $credential, $rejected);
        }

        return $request->expectsJson()
            ? response()->json(['status' => QrLogin::DENIED])
            : redirect()->route('qr.show', ['credential' => $credential]);
    }

    private function showToApprover(Request $request, QrLogin $qrLogin, string $credential, User $user): Response|RedirectResponse
    {
        if ($qrLogin->effectiveStatus() !== QrLogin::PENDING) {
            return $this->render($this->outcome($qrLogin, $user), $request, $qrLogin);
        }

        if (! $this->policy->recentlyConfirmed($request->session()->get('auth.password_confirmed_at'))) {
            $request->session()->put('url.intended', $request->fullUrl());

            return redirect()->route('reauthenticate.show');
        }

        return $this->render('approve', $request, $qrLogin, [
            'credential' => $credential,
            'options' => $qrLogin->numberOptions(),
            'email' => $user->email,
        ]);
    }

    private function showToRequester(Request $request, QrLogin $qrLogin, string $credential): Response
    {
        $mine = $this->policy->requesterFor($request->session());

        if ($mine?->is($qrLogin) === true) {
            return $this->render('waiting', $request, $qrLogin, ['number' => $qrLogin->match_number]);
        }

        return $this->render(
            $qrLogin->effectiveStatus() === QrLogin::PENDING && ! $qrLogin->isClaimed() ? 'claim' : 'taken',
            $request,
            $qrLogin,
            ['credential' => $credential],
        );
    }

    private function outcome(QrLogin $qrLogin, User $user): string
    {
        $status = $qrLogin->effectiveStatus();
        $mine = $qrLogin->approver_user_id === $user->id;

        return match (true) {
            $status === QrLogin::APPROVED && $mine, $status === QrLogin::REDEEMED && $mine => 'approved',
            $status === QrLogin::DENIED => 'denied',
            $status === QrLogin::EXPIRED => 'expired',
            default => 'taken',
        };
    }

    /**
     * @param  array<string, mixed>  $props
     */
    private function render(string $mode, Request $request, QrLogin $qrLogin, array $props = []): Response
    {
        return Inertia::render('auth/QrShow', [
            'mode' => $mode,
            'notice' => $request->session()->get('qr_notice'),
            'request' => $qrLogin->requesterDevice($this->fingerprints),
            'credential' => null,
            'options' => [],
            'number' => null,
            'email' => null,
            ...$props,
        ]);
    }

    private function rejected(Request $request, string $credential, QrLoginRejected $rejected): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            if ($rejected->reason === QrLoginRejected::REAUTH_REQUIRED) {
                $request->session()->put('url.intended', route('sign-in-another-device.edit'));
            }

            return response()->json([
                'reason' => $rejected->reason,
                'message' => $rejected->getMessage(),
                'reauthenticate' => $rejected->reason === QrLoginRejected::REAUTH_REQUIRED ? route('reauthenticate.show') : null,
            ], $rejected->httpStatus());
        }

        if ($rejected->reason === QrLoginRejected::REAUTH_REQUIRED) {
            $request->session()->put('url.intended', route('qr.show', ['credential' => $credential]));

            return redirect()->route('reauthenticate.show');
        }

        return redirect()->route('qr.show', ['credential' => $credential])->with('qr_notice', $rejected->getMessage());
    }
}
