-- READ-ONLY diagnostics for the Sent view/count. Run inside a read-only transaction.
BEGIN READ ONLY;
SELECT count(*) AS total_messages, count(*) FILTER (WHERE deleted_at IS NULL) AS live_messages FROM messages;
SELECT count(DISTINCT ml.message_id) AS distinct_messages_with_active_sent_membership
FROM message_locations ml JOIN remote_folders rf ON rf.id = ml.remote_folder_id
WHERE rf.role = 'sent' AND rf.removed_at IS NULL AND ml.removed_at IS NULL;
SELECT rf.role, rf.name, rf.sync_enabled, count(*) AS active_locations, count(DISTINCT ml.message_id) AS distinct_messages
FROM message_locations ml JOIN remote_folders rf ON rf.id = ml.remote_folder_id
WHERE ml.removed_at IS NULL GROUP BY 1, 2, 3 ORDER BY 4 DESC;
SELECT f.system_role AS messages_folder_id_role, count(*) FROM messages m JOIN folders f ON f.id = m.folder_id
WHERE m.deleted_at IS NULL GROUP BY 1;
SELECT direction, count(*) FROM messages WHERE deleted_at IS NULL GROUP BY 1;
SELECT mail_account_id, count(*) FROM messages WHERE deleted_at IS NULL GROUP BY 1;
ROLLBACK;
