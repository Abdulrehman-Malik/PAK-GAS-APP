# Database migrations

database/schema.sql is the clean-install baseline.

For future database changes, add one numbered SQL file here, for example:

~~~text
001_add_example_column.sql
002_create_example_index.sql
~~~

Rules:
- Use a new filename for every migration.
- Do not edit a migration after it has been applied to a database.
- The application detects unapplied files automatically.
- When a pending migration exists, normal application requests redirect to /install.
- The installation/update page runs all pending migrations in filename order.
- Applied migrations are recorded in schema_migrations with a SHA-256 checksum.
