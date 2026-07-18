-- Simplelogin Plugin Update: Voeg nieuwe log types toe voor goedkeuren/afkeuren
ALTER TABLE `#__simple_login_log`
MODIFY COLUMN `type` ENUM(
    'AccountEvent',
    'DebugDiagnostics',
    'DebugFlowTrace',
    'DebugRequestTrace',
    'InviteFlow',
    'LoginFlow',
    'SecurityIncident',
    'admin_approved_registration',
    'admin_rejected_registration'
) NOT NULL;