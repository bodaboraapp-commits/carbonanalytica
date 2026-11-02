<?php

declare(strict_types=1);

use App\Tenancy;

return [
    'ranks roles correctly' => static function (): void {
        assert_true(Tenancy::roleAtLeast('owner', 'admin'));
        assert_true(Tenancy::roleAtLeast('admin', 'editor'));
        assert_true(Tenancy::roleAtLeast('editor', 'viewer'));
        assert_true(Tenancy::roleAtLeast('viewer', 'viewer'));

        assert_false(Tenancy::roleAtLeast('viewer', 'editor'));
        assert_false(Tenancy::roleAtLeast('editor', 'admin'));
        assert_false(Tenancy::roleAtLeast('admin', 'owner'));
    },

    'treats unknown roles as the lowest rank' => static function (): void {
        assert_false(Tenancy::roleAtLeast('superuser', 'viewer'));
        assert_false(Tenancy::roleAtLeast('superuser', 'owner'));
    },
];
