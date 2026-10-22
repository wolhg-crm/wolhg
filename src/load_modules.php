<?php

declare(strict_types=1);

/**
 * @return array<string, list<string>>
 */
function application_modules(): array
{
    return [
        'ChurchTenant.php' => [
            'app_path',
            'resolve_church_tenant_from_request_path',
            'legacy_default_slug',
            'is_super_admin',
            'require_super_admin',
        ],
        'DemoAccess.php' => [
            'demo_enter_and_redirect',
            'demo_reset_and_redirect',
            'is_demo_session',
            'demo_banner_markup',
            'demo_guard_request',
            'resolve_demo_tenant_from_request_path',
        ],
        'Brand.php' => [],
        'AppNav.php' => [
            'app_sidebar_nav_catalog',
            'app_sidebar_nav',
            'app_sidebar_visible_labels',
        ],
        'PwaManifest.php' => [
            'pwa_manifest_for_church',
            'pwa_manifest_href',
            'pwa_normalize_manifest_slug',
            'pwa_manifest_slug_is_allowed',
            'send_pwa_manifest_response',
        ],
        'ChurchLanding.php' => [
            'church_about_page',
            'church_landing_load',
            'church_landing_normalize_network_href',
            'church_landing_default_buttons',
        ],
        'ChurchLandingEditor.php' => [
            'church_landing_editor_page',
            'handle_church_landing_post',
            'church_landing_from_post',
        ],
        'Domain.php' => [],
        'Migrate.php' => [
            'migrate_run',
            'migrate_pending_versions',
            'migrate_auto_run',
            'migrate_handle_http_request',
        ],
        'HomeGroup.php' => [],
        'OsmDistrictCatalog.php' => [
            'osm_district_catalog_fetch',
            'osm_district_catalog_parse_overpass_elements',
        ],
        'Districts.php' => [
            'districts_page',
            'handle_districts_post',
            'church_has_active_districts',
            'user_district_ids',
            'home_group_apply_district',
        ],
        'HomeGroupCatalog.php' => [
            'homegroup_catalog_page',
            'homegroup_join_page',
            'homegroup_catalog_api',
        ],
        'Knowledge.php' => [
            'knowledge_render_body',
            'knowledge_store_uploaded_image',
            'knowledge_is_safe_image_path',
        ],
        'Miracles.php' => [
            'miracles_page',
            'miracle_page',
            'miracle_share_public_page',
            'miracle_upsert_from_prayer',
            'miracle_create_standalone',
        ],
        'PrayerPublic.php' => [
            'prayer_request_public_page',
        ],
        'HomeGroupLeaderCourse.php' => [
            'home_group_leader_course_data',
        ],
        'SystemGuide.php' => [
            'system_guide_data',
            'system_guide_visible_tree',
            'system_guide_article_ids',
        ],
        'Features.php' => [],
        'Account.php' => [],
        'ChurchOnboarding.php' => [
            'church_invite_storage_ready',
            'create_church_invite',
            'church_invite_from_token',
            'consume_church_invite',
            'church_invite_page',
            'render_admin_church_invites_panel',
        ],
        'Mail.php' => [],
        'Social.php' => [],
        'Bots.php' => [],
        'Care.php' => [
            'care_people',
            'care_inactive_people',
            'group_health_stats',
            'church_analytics_stats',
            'meeting_create_for_user',
            'meeting_save_attendance_for_user',
            'care_strip_sensitive_person_fields',
            'care_public_person_row',
        ],
        'McpApiKeys.php' => [
            'mcp_api_key_scopes',
            'mcp_resolve_bearer_token',
            'mcp_create_api_key',
            'mcp_list_api_keys',
            'mcp_revoke_api_key',
        ],
        'McpOAuth.php' => [
            'mcp_oauth_is_enabled',
            'mcp_oauth_resource_url',
            'mcp_oauth_www_authenticate',
            'mcp_oauth_protected_resource_metadata_url',
        ],
        'McpOAuthStore.php' => [
            'mcp_oauth_register_dcr',
            'mcp_oauth_resolve_client',
            'mcp_oauth_issue_code',
            'mcp_oauth_consume_code',
            'mcp_oauth_issue_tokens',
            'mcp_oauth_rotate_refresh_token',
            'mcp_oauth_authenticate_access_token',
            'mcp_oauth_revoke_token',
        ],
        'McpOAuthEndpoints.php' => [
            'mcp_oauth_handle_request',
        ],
        'McpTools.php' => [
            'mcp_tool_definitions',
            'mcp_call_tool',
        ],
        'Mcp.php' => [
            'mcp_handle_http_request',
            'mcp_is_enabled',
        ],
        'RolePreview.php' => [
            'handle_role_preview_post',
            'role_preview_blocks_mutation',
        ],
    ];
}

function load_application_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    foreach (application_modules() as $module => $functions) {
        $path = __DIR__ . '/' . $module;
        if (!is_file($path)) {
            throw new RuntimeException(
                'Missing application module ' . $module . '. Upload the complete src/ directory to the server.'
            );
        }

        require_once $path;

        foreach ($functions as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException(
                    'Application module ' . $module . ' did not define ' . $function . '.'
                );
            }
        }
    }
}
