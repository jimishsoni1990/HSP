# Global Credentials

These are the FIXED credentials for the local development environment.
**STRICT RULE:** NO AGENT IS ALLOWED TO CHANGE, RESET, OR MODIFY THESE CREDENTIALS UNDER ANY CIRCUMSTANCES.

## WordPress Credentials
- **URL**: `http://localhost:8080`
- **Admin Username**: `admin`
- **Admin Password**: `password`
- **Admin Email**: `admin@example.com`
- **Application Password (REST API)**: `VLz9nwhALMvAWbVoFTNHZXJY`

## Database Credentials (MySQL)
- **Host**: `mysql:3306` (or `localhost:3306` from host)
- **Database**: `wordpress`
- **User**: `wordpress_user`
- **Password**: `wordpress_password`
- **Root Password**: `root_secret`

## Database Credentials (PostgreSQL)
- **Host**: `postgres:5432` (or `localhost:5433` from host)
- **Database**: `hsp_delivery`
- **User**: `hsp_admin`
- **Password**: `hsp_secret`

## Local by WP Engine Site (Alternative Host Setup)
- **URL**: `http://hsp.local`
- **PostgreSQL Host**: `localhost:5433` (from host)
- **Active php.ini Template**: `C:\Users\jimis\Local Sites\hsp\conf\php\php.ini.hbs`
- **Note**: The template `php.ini.hbs` must have `extension=php_pgsql.dll` and `extension=php_pdo_pgsql.dll` enabled under the `os.windows` block. Do not modify the compiled `php.ini` directly as it is generated dynamically on site startup.
