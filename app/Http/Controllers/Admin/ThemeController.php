<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\NetworkSettings;
use App\Support\Themes;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ThemeController extends Controller
{
    public function edit(NetworkSettings $settings)
    {
        $state = $settings->state();
        $theme = $settings->revision($state->draft_id ?? $state->published_id);
        $warnings = [];
        foreach ($theme['palette'] as $mode => $tokens) {
            foreach ([['text', 'page'], ['text', 'card'], ['muted', 'card'], ['link', 'page'], ['button_text', 'button']] as [$text, $surface]) {
                if (Themes::contrast($tokens[$text], $tokens[$surface]) < 4.5) {
                    $warnings[] = ucfirst($mode).' · '.Themes::TOKENS[$text].' on '.strtolower(Themes::TOKENS[$surface]).' has contrast below 4.5:1.';
                }
            }
        }

        return view('admin.theme', [
            'state' => $state, 'theme' => $theme, 'warnings' => $warnings,
            'tokens' => Themes::TOKENS, 'presets' => Themes::LABELS,
            'palettes' => array_map(Themes::preset(...), array_keys(Themes::LABELS)),
        ]);
    }

    public function draft(Request $request, NetworkSettings $settings)
    {
        $rules = ['configuration_version' => 'nullable|string|size:64', 'preset' => ['required', Rule::in(array_keys(Themes::LABELS))]];
        if ($request->input('preset') === 'custom') {
            foreach (['light', 'dark'] as $mode) {
                foreach (Themes::TOKENS as $token => $label) {
                    $rules["palette.$mode.$token"] = ['required', 'string', 'regex:/^#[a-fA-F0-9]{6}$/D'];
                }
            }
        }
        $data = $request->validate($rules);
        $palette = $data['palette'] ?? Themes::preset($data['preset']);
        $settings->saveDraft($request->user()->id, fn (array $current) => array_replace($current, [
            'preset' => $data['preset'], 'palette' => $palette,
        ]), $data['configuration_version'] ?? null);

        return redirect('/admin/theme')->with('status', 'Theme draft saved.');
    }

    public function publish(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['configuration_version' => 'nullable|string|size:64']);
        $settings->publish($request->user()->id, expected: $data['configuration_version'] ?? null);

        return redirect('/admin/theme')->with('status', 'Theme published across the network.');
    }

    public function rollback(Request $request, NetworkSettings $settings)
    {
        $data = $request->validate(['configuration_version' => 'nullable|string|size:64']);
        $settings->publish($request->user()->id, rollback: true, expected: $data['configuration_version'] ?? null);

        return redirect('/admin/theme')->with('status', 'Previous published configuration restored.');
    }
}
