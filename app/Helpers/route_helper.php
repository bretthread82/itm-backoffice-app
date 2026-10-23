<?php

declare(strict_types=1);

if (! function_exists('route_step_row')) {
    /**
     * HTML for one step in the route builder. Used for each saved step and, with idx "__IDX__",
     * for the "add step" template. Kept as a plain function so it can be called from inside a
     * layout section (nested view() calls would disturb CodeIgniter's section handling).
     *
     * @param array<string, mixed>         $step
     * @param list<array<string, mixed>>   $users
     * @param list<array<string, mixed>>   $teams
     */
    function route_step_row(string $idx, array $step, array $users, array $teams = []): string
    {
        ob_start();
        include APPPATH . 'Helpers/route_step_row.view.php';

        return (string) ob_get_clean();
    }
}
