-- The sales office reads from the top down:
-- 3rd line manager → 2nd line manager → 1st line manager → headquarters → stockist.
-- A 3rd line manager has no parent. A headquarters belongs to one 1st line manager.
-- Stockists stay on headquarters.
--
-- Only a complete one-to-one chain is reversed, because that mapping is
-- unambiguous. Any other existing chain is left as stored. The new check then
-- fails the migration instead of guessing a parent, and the file can be fixed
-- before it is recorded.

ALTER TABLE headquarters
    ADD COLUMN parent_manager_id BIGINT UNSIGNED NULL AFTER phone,
    ADD KEY headquarters_parent_manager_id_index (parent_manager_id);

ALTER TABLE managers DROP CHECK managers_parent_check;

CREATE TEMPORARY TABLE sales_office_flip (
    l1_id BIGINT UNSIGNED NOT NULL,
    l2_id BIGINT UNSIGNED NOT NULL,
    l3_id BIGINT UNSIGNED NOT NULL,
    hq_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (l1_id),
    UNIQUE KEY sales_office_flip_l2_unique (l2_id),
    UNIQUE KEY sales_office_flip_l3_unique (l3_id),
    UNIQUE KEY sales_office_flip_hq_unique (hq_id)
);

INSERT INTO sales_office_flip (l1_id, l2_id, l3_id, hq_id)
SELECT l1.id, l2.id, l3.id, hq.id
FROM managers l1
INNER JOIN headquarters hq ON hq.id = l1.parent_headquarters_id
INNER JOIN managers l2 ON l2.level = 2 AND l2.parent_manager_id = l1.id AND l2.parent_headquarters_id IS NULL
INNER JOIN managers l3 ON l3.level = 3 AND l3.parent_manager_id = l2.id AND l3.parent_headquarters_id IS NULL
WHERE l1.level = 1
  AND l1.parent_manager_id IS NULL
  AND l1.parent_headquarters_id IS NOT NULL
  AND (SELECT COUNT(*) FROM managers x WHERE x.level = 1 AND x.parent_headquarters_id = hq.id) = 1
  AND (SELECT COUNT(*) FROM managers x WHERE x.level = 2 AND x.parent_manager_id = l1.id) = 1
  AND (SELECT COUNT(*) FROM managers x WHERE x.level = 3 AND x.parent_manager_id = l2.id) = 1;

UPDATE managers l3
INNER JOIN sales_office_flip flip ON flip.l3_id = l3.id
SET l3.parent_manager_id = NULL, l3.parent_headquarters_id = NULL;

UPDATE managers l2
INNER JOIN sales_office_flip flip ON flip.l2_id = l2.id
SET l2.parent_manager_id = flip.l3_id, l2.parent_headquarters_id = NULL;

UPDATE managers l1
INNER JOIN sales_office_flip flip ON flip.l1_id = l1.id
SET l1.parent_manager_id = flip.l2_id, l1.parent_headquarters_id = NULL;

UPDATE headquarters hq
INNER JOIN sales_office_flip flip ON flip.hq_id = hq.id
SET hq.parent_manager_id = flip.l1_id;

DROP TEMPORARY TABLE sales_office_flip;

ALTER TABLE headquarters
    ADD CONSTRAINT headquarters_parent_manager_id_foreign FOREIGN KEY (parent_manager_id) REFERENCES managers (id);

ALTER TABLE managers
    ADD CONSTRAINT managers_parent_check CHECK (
        (level = 3 AND parent_headquarters_id IS NULL AND parent_manager_id IS NULL)
        OR (level = 2 AND parent_headquarters_id IS NULL AND parent_manager_id IS NOT NULL)
        OR (level = 1 AND parent_headquarters_id IS NULL AND parent_manager_id IS NOT NULL)
    );
