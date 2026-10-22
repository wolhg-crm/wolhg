SET NAMES utf8mb4;

DELETE open_task
FROM tasks open_task
JOIN tasks done_task
  ON done_task.id <> open_task.id
 AND done_task.person_id <=> open_task.person_id
 AND done_task.assigned_to <=> open_task.assigned_to
 AND done_task.type = open_task.type
 AND done_task.due_date = open_task.due_date
 AND done_task.status = 'done'
WHERE open_task.status = 'open';

ALTER TABLE tasks
  DROP INDEX uniq_open_task,
  ADD COLUMN active_task_key VARCHAR(8)
    GENERATED ALWAYS AS (CASE WHEN status IN ('open','done') THEN 'active' ELSE NULL END) STORED
    AFTER completed_at,
  ADD UNIQUE KEY uniq_active_task (person_id, assigned_to, type, due_date, active_task_key);
