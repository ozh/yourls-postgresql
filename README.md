# PostgreSQL Support for YOURLS [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/) [![CI](https://github.com/ozh/yourls-postgresql/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/ozh/yourls-postgresql/actions/workflows/ci.yml)
> <img width="200" alt="yourls-love-pgsql" src="https://github.com/user-attachments/assets/96e844c8-100e-4b56-9cd7-e256d0d2d458" /> 

Requires [YOURLS](https://yourls.org) `1.10.4` and above.

#### Table of Contents

- [Quick start](#quick-start)
- [Installation](#installation)
- [Contributing](#contributing)
- [License](#license)

## Quick start

:information_source: **This is not a plugin**.

A rather little known feature of YOURLS is that you can customize the DB engine or define
a new one with a custom `user/db.php` file : **simply drop `db.php` in `user/`**. There is
nothing to look for or activate in the Plugins admin page.

Starting with YOURLS `1.10.4`, SQL queries can be rewritten on the fly. This code modifies each
MySQL query to be compatible with [PostgreSQL](https://www.postgresql.org/). It should pass all
[YOURLS unit tests](https://github.com/ozh/yourls-postgresql/actions/workflows/ci.yml?query=branch%3Amaster)
under various PostgreSQL versions, and work with all YOURLS features, including plugins.

## Installation

### Prerequisites : PostgreSQL up and running, obviously

This is out of scope, but things may be along the lines of:

* `postgres` in your `docker-compose.yml`:
  ```yaml
    postgres:
      image: postgres:16
      container_name: dev-postgres
      environment:
        POSTGRES_PASSWORD: root
        POSTGRES_DB: dev
      ports:
        - "5432:5432"
      volumes:
        - postgres_data:/var/lib/postgresql/data
  ```
* Needed libraries and binaries :
  ```sh
  sudo apt install postgresql-client-common
  sudo apt install postgresql-client-16
  ```

Modify this as needed for your setup -- refer to available documentation if unsure.


### Create user and database, matching your `config.php`:

Connect to `psql` (with password `root` if using Docker setup above):

```bash
psql -h localhost -p 5432 -U postgres -d dev
```

Then in the `psql` shell, type:
```sql
-- Create user 'yourls_user' with password 'yourls_password'
DROP USER IF EXISTS yourls;
CREATE USER yourls_user WITH PASSWORD 'yourls_password';

-- Create DB 'yourls_db'
CREATE DATABASE yourls_db OWNER yourls_user;

-- Give all privileges to user 'yourls_user' on DB 'yourls_db'
GRANT ALL PRIVILEGES ON DATABASE yourls_db TO yourls_user;

-- Check user, check databases
\du
\l

-- Once everything is OK, quit the psql shell
\q
```

A matching YOURLS `config.php` would look like this:
```php
define('YOURLS_DB_USER', 'yourls_user');
define('YOURLS_DB_PASS', 'yourls_password');
define('YOURLS_DB_HOST', 'postgres'); // or for instance 'localhost:5432' if not using Docker
define('YOURLS_DB_NAME', 'yourls_db'); 
```

### Migrate existing data

If you have an existing YOURLS installation with data in MySQL, you can migrate it to PostgreSQL using the provided `pgsql_migration.php` script.

Edit `pgsql_migration.php` to set the correct MySQL and PostgreSQL connection parameters, then run:

```sh
php pgsql_migration.php
```

## Contributing

Contributions are very welcome! I aim to keep this DB engine compatible with every future
YOURLS release, and pass all unit tests against current YOURLS `master`

If you find a bug, please open an issue or, better, submit a pull request on GitHub.

## License

Do whatever the hell you want.
