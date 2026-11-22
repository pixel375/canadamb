SET @brandon := (SELECT id FROM locations WHERE slug = 'brandon');

INSERT INTO community_threads (id, author_id, location_id, title, body, category, status, pinned, locked, last_activity_at, created_at, updated_at) VALUES
    (8502, 8501, @brandon, 'Pending discussion', 'An owner and moderator will act on the same revision.', 'general', 'pending', FALSE, FALSE, NULL, '2026-01-01 10:00:00', '2026-01-01 10:00:00'),
    (8503, 8501, NULL, 'Rejected provincial discussion', 'Only the owner can see this historical rejection.', 'help', 'rejected', FALSE, FALSE, NULL, '2026-01-01 10:01:00', '2026-01-01 10:10:00'),
    (8504, 8501, @brandon, 'Locked discussion', 'The author cannot edit this locked thread.', 'general', 'approved', FALSE, TRUE, '2026-01-01 10:20:00', '2026-01-01 10:00:00', '2026-01-01 10:25:00');

INSERT INTO community_replies (id, thread_id, author_id, body, status, created_at, updated_at) VALUES
    (8503, 8501, 8502, 'Pending reply.', 'pending', '2026-01-01 10:05:00', '2026-01-01 10:05:00'),
    (8504, 8501, 8502, 'Rejected reply.', 'rejected', '2026-01-01 10:06:00', '2026-01-01 10:11:00'),
    (8505, 8504, 8502, 'Previously approved reply with a unified followed notice.', 'approved', '2026-01-01 10:07:00', '2026-01-01 10:20:00');
INSERT INTO community_thread_follows (thread_id, user_id, created_at) VALUES (8504, 8501, '2026-01-01 10:04:00');

INSERT INTO audit_logs (id, actor_id, action, context, created_at) VALUES
    (8510, 8503, 'community.thread.reject', '{"thread":8503}', '2026-01-01 10:10:00'),
    (8511, 8503, 'community.reply.reject', '{"reply":8504}', '2026-01-01 10:11:00'),
    (8512, 8503, 'community.thread.approve', '{"thread":8504}', '2026-01-01 10:12:00'),
    (8513, 8503, 'community.reply.approve', '{"reply":8505}', '2026-01-01 10:20:00'),
    (8514, 8503, 'community.thread.lock', '{"thread":8504}', '2026-01-01 10:25:00'),
    (8515, 8503, 'moderation.report-resolved', '{"type":"reply","report":8502}', '2026-01-01 10:27:00');
INSERT INTO moderation_decisions (id, type, resource_id, action, audit_id, notice_source_id, reason, notes, created_at) VALUES
    (8510, 'thread', 8503, 'reject', 8510, 8510, 'Clarify the discussion topic.', 'Private moderator note.', '2026-01-01 10:10:00'),
    (8511, 'reply', 8504, 'reject', 8511, 8511, 'Please keep replies on topic.', 'Private reply review note.', '2026-01-01 10:11:00'),
    (8512, 'thread', 8504, 'approve', 8512, 8512, NULL, NULL, '2026-01-01 10:12:00'),
    (8513, 'reply', 8505, 'approve', 8513, 8513, NULL, NULL, '2026-01-01 10:20:00'),
    (8514, 'thread', 8504, 'lock', 8514, NULL, NULL, 'Preserve the prior lock.', '2026-01-01 10:25:00');

INSERT INTO content_reports (id, source_type, source_id, location_id, reporter_id, reason, created_at) VALUES
    (8501, 'thread', 8501, @brandon, 8502, 'Unresolved thread report.', '2026-01-01 10:25:00'),
    (8502, 'reply', 8505, @brandon, 8501, 'Resolved report with exact reply attribution.', '2026-01-01 10:26:00'),
    (8503, 'business', 8501, NULL, 8502, 'A historical report made on the provincial portal.', '2026-01-01 10:28:00');
INSERT INTO moderation_report_decisions (id, report_type, report_id, decision, note, reviewer_id, audit_log_id, created_at) VALUES
    (8501, 'reply', 8502, 'resolved', 'Historical report decision.', 8503, 8515, '2026-01-01 10:27:00');

INSERT INTO notifications (id, user_id, event, source_id, resource_id, read_at, created_at) VALUES
    (8510, 8501, 'thread.rejected', 8510, 8503, NULL, '2026-01-01 10:10:00'),
    (8511, 8502, 'reply.rejected', 8511, 8504, '2026-01-01 10:12:00', '2026-01-01 10:11:00'),
    (8512, 8501, 'thread.approved', 8512, 8504, '2026-01-01 10:17:00', '2026-01-01 10:12:00'),
    (8513, 8502, 'reply.approved', 8513, 8505, NULL, '2026-01-01 10:20:00'),
    (8514, 8501, 'reply.followed', 8505, 8505, NULL, '2026-01-01 10:20:00');
INSERT INTO notification_locations (notification_id, location_id) VALUES
    (8511, @brandon), (8512, @brandon), (8513, @brandon), (8514, @brandon);

-- Two populated threads and mixed approval states exercise real pagination and anchor lookups.
INSERT INTO community_replies (thread_id, author_id, body, status, created_at, updated_at)
    SELECT IF(MOD(seq, 3) = 0, 8504, 8501), 8502, CONCAT('Synthetic page reply ', seq),
        IF(MOD(seq, 5) = 0, 'pending', 'approved'), '2026-01-01 10:08:00', '2026-01-01 10:08:00'
    FROM seq_1_to_2500;
