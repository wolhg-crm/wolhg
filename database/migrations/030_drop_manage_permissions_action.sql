-- Role matrix is super-admin only. The manage_permissions action no longer gates any screen.
DELETE FROM role_permissions WHERE action = 'manage_permissions';
