-- Extra e2e data the fixtures do not have (loaded by run.sh after the fixtures).
-- A CS manager (member): the head's own exclusion requests are approved at once, so calls.spec.ts needs somebody
-- whose request waits for a decision. Same password as the fixture users (hash copied from one of them).
INSERT INTO `user` (email, name, roles, password, is_active, created_at)
SELECT 'cs.member@rnp.test', 'CS Manager', roles, password, 1, NOW() FROM `user` WHERE email = 'revenue.member@rnp.test';
INSERT INTO org_membership (role, user_id, org_unit_id)
SELECT 'member', (SELECT id FROM `user` WHERE email = 'cs.member@rnp.test'), id FROM org_unit WHERE name = 'Customer Service';

-- Two missed incoming Bitrix calls two days ago (times are Madrid, like the sync stores them):
-- calls.spec.ts excludes one of them and checks the recalculated CS metric.
INSERT INTO raw_bitrix_call (id, direction, missed, completed, start_time, end_time, owner_type_id, owner_id, responsible_id, last_updated, payload, synced_at)
VALUES
  (900001, 1, 1, 1, CURDATE() - INTERVAL 2 DAY + INTERVAL 10 HOUR, CURDATE() - INTERVAL 2 DAY + INTERVAL 10 HOUR, 3, 501, 14, NOW(), '{}', NOW()),
  (900002, 1, 1, 1, CURDATE() - INTERVAL 2 DAY + INTERVAL 11 HOUR, CURDATE() - INTERVAL 2 DAY + INTERVAL 11 HOUR, 3, 502, 14, NOW(), '{}', NOW());
