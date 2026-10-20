"""Disposable WordPress/MariaDB integration check with synthetic content only."""

from pathlib import Path
import json
import secrets
import shutil
import subprocess
import tempfile
import time
import uuid

ROOT = Path(__file__).resolve().parents[1]
SUFFIX = uuid.uuid4().hex[:8]
NETWORK = "nexpilot-qa-" + SUFFIX
DATABASE = "nexpilot-db-" + SUFFIX
PASSWORD = secrets.token_hex(16)


def run(args, *, check=True):
    result = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    if check and result.returncode:
        raise RuntimeError("Command failed: " + repr(args[:5]) + "\n" + result.stdout[-800:])
    return result


def main():
    run(["docker", "network", "create", NETWORK])
    try:
        run([
            "docker", "run", "--rm", "-d", "--name", DATABASE, "--network", NETWORK,
            "-e", "MARIADB_ROOT_PASSWORD=" + PASSWORD,
            "-e", "MARIADB_DATABASE=wp", "-e", "MARIADB_USER=wp",
            "-e", "MARIADB_PASSWORD=" + PASSWORD, "mariadb:11.4",
        ])
        with tempfile.TemporaryDirectory(prefix="nexpilot-wordpress-") as temporary:
            site = Path(temporary)
            site.chmod(0o777)

            def wp(*arguments):
                return run([
                    "docker", "run", "--rm", "--network", NETWORK, "--user", "0",
                    "-v", str(site) + ":/var/www/html", "-w", "/var/www/html",
                    "wordpress:cli-php8.1", "wp", *arguments, "--allow-root",
                ]).stdout.strip()

            wp("core", "download", "--skip-content")
            for _ in range(30):
                ping = run([
                    "docker", "exec", DATABASE, "mariadb-admin", "ping",
                    "-h127.0.0.1", "-uroot", "-p" + PASSWORD, "--silent",
                ], check=False)
                if ping.returncode == 0:
                    break
                time.sleep(1)
            else:
                raise RuntimeError("MariaDB did not start")

            wp("config", "create", "--dbname=wp", "--dbuser=wp", "--dbpass=" + PASSWORD, "--dbhost=" + DATABASE)
            wp(
                "core", "install", "--url=http://127.0.0.1:9488", "--title=Synthetic QA",
                "--admin_user=synthetic_admin", "--admin_password=" + secrets.token_hex(16),
                "--admin_email=admin@example.invalid", "--skip-email",
            )
            target = site / "wp-content/plugins/nexpilot-community"
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copytree(ROOT / "plugin", target)
            wp("plugin", "activate", "nexpilot-community")

            result = wp("eval", r'''
$_SERVER['HTTPS'] = 'on';
$published = wp_insert_post(['post_type'=>'post','post_status'=>'publish','post_title'=>'Synthetic published','post_content'=>'Public body']);
$draft = wp_insert_post(['post_type'=>'post','post_status'=>'draft','post_title'=>'Synthetic draft','post_content'=>'Private body']);
$token = \Nexdev\NexPilotCommunity\Credentials::issue('Synthetic client');
$request = function($id) use ($token) {
    $query = new WP_REST_Request('POST', '/nexpilot-community/v1/mcp');
    $query->set_header('authorization', 'Bearer ' . $token);
    $query->set_header('content-type', 'application/json');
    $query->set_body(wp_json_encode(['jsonrpc'=>'2.0','id'=>1,'method'=>'tools/call','params'=>['name'=>'content_get','arguments'=>['id'=>$id]]]));
    return rest_do_request($query);
};
$public = $request($published);
$private = $request($draft);
\Nexdev\NexPilotCommunity\Credentials::revoke(explode('.', $token)[0]);
$revoked = $request($published);
$public_data = $public->get_data();
$private_data = $private->get_data();
echo wp_json_encode([
    'public_status' => $public->get_status(),
    'public_content' => strpos(wp_json_encode($public_data), 'Public body') !== false,
    'private_denied' => ($private_data['result']['isError'] ?? null) === true,
    'private_content_absent' => strpos(wp_json_encode($private_data), 'Private body') === false,
    'revoked_status' => $revoked->get_status(),
]);
''')
            observed = json.loads(result)
            expected = {
                "public_status": 200,
                "public_content": True,
                "private_denied": True,
                "private_content_absent": True,
                "revoked_status": 403,
            }
            if observed != expected:
                raise AssertionError(f"Integration boundary failed: {observed}")
            wp("plugin", "uninstall", "nexpilot-community", "--deactivate")
            remaining = run([
                "docker", "run", "--rm", "--network", NETWORK, "--user", "0",
                "-v", str(site) + ":/var/www/html", "-w", "/var/www/html",
                "wordpress:cli-php8.1", "wp", "option", "get", "nexpilot_community_clients", "--allow-root",
            ], check=False)
            if remaining.returncode == 0:
                raise AssertionError("Uninstall retained connector credentials")
            print("PASS disposable WordPress activation, published read, draft denial, revocation, and uninstall")
    finally:
        subprocess.run(["docker", "stop", DATABASE], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        subprocess.run(["docker", "network", "rm", NETWORK], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


if __name__ == "__main__":
    main()
