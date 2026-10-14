# Tagged Backup for Nextcloud

Back up every file and folder that carries a chosen **collaborative (system) tag**. Each run:

1. finds everything tagged with any of the job's tags (tagged folders include all their contents),
2. writes a **tar** archive with a **`manifest.json`** inside (paths, sizes, mtimes, SHA-256 checksums, tags, owners),
3. optionally **compresses** it (gzip or bzip2),
4. optionally **encrypts** it for one or more [age](https://age-encryption.org) public keys,
5. uploads it to **S3-compatible storage**, **Google Drive** (OAuth redirect or device sign-in) or **Microsoft OneDrive**,
6. optionally deletes old backups so only the newest *N* are kept.

Jobs run on a schedule (Nextcloud background jobs), on demand from the admin settings page ("Run now"), or with `occ tagged-backup:run`.

## Requirements

- Nextcloud 30–35 (developed and tested on 35.0.1), PHP 8.1+
- PHP extensions: `sodium`, `zlib`; `bz2` for bzip2 compression
- **System cron** for background jobs (`Settings → Basic settings → Background jobs → Cron`). AJAX/webcron cannot run long backups.
- Enough free space in the temporary directory to hold one complete archive (see [Temporary directory](#temporary-directory))

## Installation

Clone it into your Nextcloud `apps` directory. The directory name must be `tagged_backup`, not the repository name:

```sh
cd /var/www/nextcloud/apps
sudo -u www-data git clone https://github.com/uberlinuxguy/nextcloud-tagged-backup.git tagged_backup
sudo -u www-data php ../occ app:enable tagged_backup
```

Configure jobs under **Administration settings → Tagged backup**.

## Configuring a job

| Setting | Meaning |
|---|---|
| Tags | Files and folders with **any** of these tags are included. A tagged folder includes everything inside it. |
| Only files of user | Leave empty to back up tagged files of all users. If set, only files visible to that user are included (their own files and files shared with them). |
| Compression | `gzip` (default), `bzip2`, or `none` |
| Encrypt for age recipients | One `age1…` public key per line. Leave empty to skip encryption. |
| Run every (hours) | `0` = manual only |
| Keep backups | After a successful upload, delete this job's older backups beyond this count. `0` = keep all. Only files matching this job's naming pattern are deleted. |

Use **Preview** to see what a job will include, and **Test connection** to check the destination's credentials.

### S3-compatible storage

Works with AWS S3, MinIO, Ceph RGW, SeaweedFS, Garage, Backblaze B2, Wasabi, Cloudflare R2 and others. It uses AWS Signature V4 and multipart uploads, with parts of 16 MiB or more (up to 10,000 parts).

- **Endpoint URL**: leave empty for AWS, otherwise the provider's S3 endpoint, e.g. `https://s3.eu-central-003.backblazeb2.com`
- **Path-style URLs**: needed by MinIO and most self-hosted servers. Turn it off for virtual-hosted-style (`bucket.host`).
- **Endpoint is on a local or private network**: Nextcloud blocks HTTP requests to private addresses by default. Tick this for an S3 server on your LAN.
- Minimum IAM permissions: `s3:PutObject`, `s3:DeleteObject`, `s3:ListBucket`, `s3:AbortMultipartUpload`
- Add a lifecycle rule that aborts incomplete multipart uploads. The app aborts failed uploads itself, but a crashed PHP process cannot.

### Google Drive

There are two Google Drive destinations. They upload the same way and can be used side by side; they differ only in how you connect your Google account.

Both request only the `drive.file` scope, so the app can see only the files and folders it created itself. The backup folder (default *Nextcloud Tagged Backups*) is created in the root of My Drive on first use. Two jobs that use different Google Cloud clients can't see each other's folders, even with the same folder name.

In either case, publish the OAuth consent screen or add your account as a test user. Refresh tokens for apps in "Testing" status expire after 7 days.

#### Google Drive OAuth

Sign-in through a browser redirect back to your Nextcloud.

1. In the [Google Cloud console](https://console.cloud.google.com/), enable the **Google Drive API**.
2. Create an **OAuth client ID** of type **Web application**. Use the **Redirect URI** shown in the job form as an authorized redirect URI. It looks like `https://cloud.example.com/index.php/apps/tagged_backup/oauth/callback`, and your browser must be able to reach that address.
3. Enter the client ID and secret in the job, **Save**, then click **Connect account**.

#### Google Drive Device Sign-in

Sign-in by entering a short code at [google.com/device](https://www.google.com/device). No redirect URI is involved, so it works however you reach your Nextcloud.

1. Save the job, then click **Sign in with Google**.
2. The page shows a code. Open the link on any device, sign in with the Google account to back up to, and enter the code. The page notices the approval within a few seconds.

The job needs an OAuth client of type **TVs and Limited Input devices** (create it the same way as above, with the Google Drive API enabled). The client is taken from, in order:

1. the **OAuth client ID / secret** entered in the job,
2. a server-wide default, so admins can leave the job fields empty:
   ```sh
   occ config:app:set tagged_backup gdrive_device_client_id --value='…apps.googleusercontent.com'
   occ config:app:set tagged_backup gdrive_device_client_secret --value='…' --sensitive
   ```
3. the client built into the app (`BUILTIN_CLIENT_ID` / `BUILTIN_CLIENT_SECRET` in `lib/Destination/GoogleDeviceFlow.php`), so users never need the Cloud console.

With a server default or built-in client available, the client fields are tucked away under "Use your own Google Cloud client (optional)".

##### About the built-in client

The built-in client ID and secret are published in this repository on purpose. Google treats the secret of an installed/TV app as non-confidential: holding it gives no access to anyone's Drive, because every sign-in still has to be approved by the account owner on Google's own page. Other open-source tools (rclone, `gcloud`) ship theirs the same way.

What you should know:

- **What it can access.** The client only has the `drive.file` scope: the backup folder and files it created, nothing else in your Drive. But that access is shared by every install using the built-in client.
- **Device-code phishing.** Someone could start a sign-in with the same client and trick you into entering *their* code at google.com/device. Google's consent screen would show this app's name, and the resulting token could read the backups the client created in your Drive. Only enter a code that your own Nextcloud settings page just showed you. **Encrypt backups with age** so a stolen token only exposes encrypted data. The settings page warns when a job using a shared client is not encrypted.
- **Shared fate.** If Google suspends the project or its quota is exhausted, every install using the built-in client stops working. Set your own client per job or as the server default to opt out.
- **Maintainers:** use a dedicated Google Cloud project with only the Drive API enabled, keep the scope at `drive.file`, and keep the consent screen published and verified. GitHub secret scanning may flag the secret when it is pushed; close that alert as "Won't fix", since this secret is meant to be public.

### Microsoft OneDrive

1. In [Microsoft Entra ID → App registrations](https://entra.microsoft.com/), register an application. Pick the supported account types you need.
2. Add a **Web** platform with the **Redirect URI** shown in the job form.
3. Under API permissions, add the delegated Microsoft Graph permissions **Files.ReadWrite** and **offline_access**.
4. Create a **client secret**.
5. Enter the tenant (`common`, `consumers`, `organizations`, or your tenant ID), the client ID and the secret. **Save**, then click **Connect account**.

Microsoft rotates refresh tokens. The app stores the new token automatically.

## Encryption and restoring

Encryption uses the standard [age v1 format](https://age-encryption.org/v1) with X25519 recipients. The server stores **only public keys**, so a compromised Nextcloud cannot decrypt old backups. Keep the private key somewhere other than the Nextcloud server.

```sh
# once: create a key pair; put the "age1…" public key into the job
age-keygen -o backup-key.txt

# restore
age --decrypt -i backup-key.txt  myjob-1-20261013-213324Z.tar.gz.age > backup.tar.gz
tar -xzf backup.tar.gz

# or verify all checksums straight from the encrypted file
age -d -i backup-key.txt myjob-1-20261013-213324Z.tar.gz.age | python3 tools/verify-backup.py -
```

Any age implementation works ([age](https://github.com/FiloSottile/age), [rage](https://github.com/str4d/rage), …). Add several recipients (for example a primary and an offline recovery key) to let any one of them decrypt. Passphrase and SSH-key recipients are not supported.

Data flows tar → compression → encryption, and the file name records each layer: `.tar`, `.tar.gz`, `.tar.bz2`, plus `.age` when encrypted.

## Archive layout

```
files/<user>/<path inside that user's Files>   # job covers all users
files/<path inside the user's Files>           # job limited to one user
manifest.json                                  # last entry of the archive
```

It is a POSIX (pax) tar, so long and non-ASCII paths and files over 8 GiB work with GNU tar, bsdtar, 7-Zip and Python's `tarfile`.

When several users can see a file (shares, group folders), it is stored once, under the owner's home storage where possible. Otherwise it goes under the alphabetically first user who can see it.

### manifest.json

```jsonc
{
  "format": "nextcloud-tagged-backup",
  "formatVersion": 1,
  "generator": "tagged_backup 1.0.0",
  "instance": { "id": "oc1u7k1lsdcm", "url": "https://cloud.example.com" },
  "createdAt": "2026-10-13T21:32:33+00:00",
  "job": { "id": 1, "name": "Important Stuff", "scopeUser": null,
           "tags": [{ "id": "1", "name": "backup" }] },
  "archive": { "name": "important-stuff-1-20261013-213233Z.tar.gz.age",
               "compression": "gzip", "encryption": "age-x25519" },
  "totals": { "files": 5, "directories": 5, "bytes": 43000027, "problems": 0 },
  "problems": [],              // items that could not be (fully) backed up
  "unreachableFileIds": [],    // tagged IDs not in any user's files (deleted/trashed)
  "entries": [
    { "path": "files/alice/Projects/Thesis/draft.md", "type": "file",
      "user": "alice", "nextcloudPath": "/Projects/Thesis/draft.md", "fileId": 92,
      "mtime": 1791927021, "tags": ["backup"], "mimetype": "text/markdown",
      "etag": "fe2257a66b6ef3d81d6f762e2aceefd3", "size": 13,
      "sha256": "fc0f66dae9021b66218b913e5d9d77fd2167b5815b8a953e90f613a8724ca334" }
  ]
}
```

A file that cannot be read, or that changes size while being read, does not fail the whole run. It is recorded under `problems`, its entry has an `error` instead of a `sha256`, and the run's status is **partial**.

## occ commands

```sh
occ tagged-backup:list          # jobs with last status
occ tagged-backup:preview <id>  # what a job would back up
occ tagged-backup:run <id>      # run now, with progress (exit 0 ok, 2 partial, 1 failed)
```

## Temporary directory

Each archive is fully built on local disk, then uploaded, which makes retries and resumable uploads possible. The default location is Nextcloud's temp directory. To use a larger disk:

```sh
occ config:app:set tagged_backup temp_directory --value=/srv/backup-tmp
```

The file is created with mode 0600 and deleted after every run, successful or not.

## How it works

- `SchedulerJob` runs every 15 minutes and starts any enabled job whose interval has elapsed. "Run now" queues a one-off `RunBackup` job for the next cron run.
- A per-job lock (`running_since`) prevents overlapping runs. A lock older than 24 hours is treated as stale.
- Destination credentials and OAuth refresh tokens are encrypted with Nextcloud's `ICrypto` (keyed by the instance secret). They are never sent back to the browser.
- Google Drive Device Sign-in implements Google's OAuth device authorization flow; the pending device code is kept in the admin's session, and the browser polls at the interval Google requests.
- Uploads are resumable. Google Drive uses a resumable session and OneDrive an upload session. After a failure the app asks the server how many bytes it has and continues from there. S3 retries individual parts.

## Development and tests

```sh
# archive + age pipeline, round-tripped through the real age and tar tools
age-keygen -o /tmp/key.txt
mkdir -p /tmp/out && php tests/core_test.php /tmp/out "$(grep -o 'age1.*' /tmp/key.txt)"

# Google Drive / OneDrive protocol tests against in-process fakes
# (resume after 5xx, partial chunks, lost responses, pagination, token rotation,
#  device sign-in polling and client fallback)
php tests/destination_fake_test.php /path/to/nextcloud
```

The S3 code was tested end to end against SeaweedFS, which enforces SigV4. The Google and Microsoft OAuth flow was tested in a browser up to the real token endpoint. The Drive and OneDrive upload APIs were tested against the fakes above, not against live accounts.

## License

Licensed under the [Apache License, Version 2.0](LICENSE).
