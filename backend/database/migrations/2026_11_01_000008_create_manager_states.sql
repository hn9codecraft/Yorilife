-- States assigned to a 3rd line manager. Headquarters keep their own single state.
-- Existing manager rows are not changed.

CREATE TABLE manager_states (
    manager_id BIGINT UNSIGNED NOT NULL,
    state VARCHAR(100) NOT NULL,
    PRIMARY KEY (manager_id, state),
    KEY manager_states_state_index (state),
    CONSTRAINT manager_states_manager_id_foreign FOREIGN KEY (manager_id) REFERENCES managers (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
