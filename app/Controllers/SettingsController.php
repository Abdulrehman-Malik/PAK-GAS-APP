<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\View;
use App\Core\Request;
use App\Services\AuditService;
use App\Services\SettingsService;

final class SettingsController
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Auth $auth,
        private readonly Request $request,
        private readonly AuditService $audit
    ) {
    }

    public function updateGroup(): Response
    {
        $input=$this->request->input();
        $group=trim((string)($input['setting_group']??''));
        unset($input['setting_group']);
        try{
            $this->settings->updateFields($group,$input);
            $user=$this->auth->user();
            $this->audit->record((int)$user['id'],'UPDATE','settings',null,null,['group'=>$group,'values'=>$input],$this->request->ip());
            return Response::redirect(url('/settings'));
        }catch(\Throwable $e){return Response::json(['ok'=>false,'message'=>$e->getMessage()],422);}
    }

    public function updatePos(): Response
    {
        $input = $this->request->input();
        $types = $input['transaction_types'] ?? [];
        if (!is_array($types)) {
            $types = [$types];
        }

        try {
            $this->settings->updatePosSettings($types, (string) ($input['default_transaction_type'] ?? ''));
            $user = $this->auth->user();
            $this->audit->record((int) $user['id'], 'UPDATE', 'settings', null, null, [
                'setting_group' => 'sales',
                'setting_key' => 'pos_transaction_types',
                'transaction_types' => $types,
                'default_transaction_type' => (string) ($input['default_transaction_type'] ?? ''),
            ], $this->request->ip());
            return Response::redirect(url('/settings'));
        } catch (\Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function index(): Response
    {
        return View::render('settings/index', [
            'pageTitle' => 'Settings',
            'settings' => $this->settings->all(),
            'user' => $this->auth->user(),
            '_base_path' => base_path(),
        ]);
    }
}
