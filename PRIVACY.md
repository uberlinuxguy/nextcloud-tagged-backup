# Privacy Policy — Tagged Backup for Nextcloud

*Effective date: October 13, 2026*

Tagged Backup ("the app") is an open-source app for [Nextcloud](https://nextcloud.com). It backs up files that carry selected tags to storage chosen by the Nextcloud administrator: Google Drive, Microsoft OneDrive or S3-compatible object storage. It is developed by Jason Williams ("we", "us"). The source code is public at <https://github.com/uberlinuxguy/nextcloud-tagged-backup>.

## The short version

- The app runs **entirely on your own Nextcloud server**. We do not operate any servers for it.
- **We never receive your data.** No files, backups, account details, credentials, tokens, usage statistics or telemetry are sent to us.
- Data moves only between your Nextcloud server and the storage provider you choose.
- For Google Drive, the app asks only for permission to work with **the files it creates itself**. It cannot see anything else in your Drive.

## Who is responsible for your data

Each Nextcloud server is run by its administrator, who installs the app, chooses what to back up, and chooses where backups go. For the personal data in those backups, that administrator, or the organization they act for, is responsible, not us. If you are a user of someone else's Nextcloud server, contact that server's administrator about your data.

## What the app processes

On your Nextcloud server, the app processes:

- **Files and folders carrying the tags selected in a backup job**, together with their metadata: path, owner's user ID, size, modification time, file type, file ID, tags and a SHA-256 checksum. This metadata is written into a `manifest.json` file inside each backup.
- **Backup job settings**, stored in the Nextcloud database. Storage credentials (S3 keys, OAuth client secrets and OAuth refresh tokens) are stored **encrypted** with Nextcloud's encryption key for the server.
- **A temporary copy of each backup archive**, written to the server's temporary directory while it is uploaded and deleted after every run.
- **Log entries** in the Nextcloud log, such as errors and the names of failed jobs.

All of this stays on your server, apart from the uploads described below.

## Google user data

The app offers two ways to connect a Google account: "Google Drive OAuth" and "Google Drive Device Sign-in". Both work the same way once connected.

**What we access.** The app requests a single permission (OAuth scope):

| Scope | What it allows |
|---|---|
| `https://www.googleapis.com/auth/drive.file` | Create files and folders in your Google Drive, and see, change and delete **only the files and folders created by this app**. |

The app does not request your name, email address, profile, contacts, or access to any other files in your Drive.

**How we use it.** The app uses this access only to:

1. find or create its backup folder in your Drive,
2. upload backup archives into that folder,
3. list the backups in that folder and delete old ones when the administrator has set a "Keep backups" limit.

**How it is stored.** When you connect your account, Google issues an OAuth refresh token. The app stores it encrypted in your Nextcloud server's database and uses it to get short-lived access tokens, which are only kept in memory while a backup runs. These tokens are never sent to us or to anyone other than Google.

**How it is shared.** Google user data is not shared with us or any third party, and is not used for advertising, analytics, profiling, or training AI or machine-learning models. Data flows only between your Nextcloud server and Google.

**Limited Use.** Tagged Backup's use and transfer of information received from Google APIs adheres to the [Google API Services User Data Policy](https://developers.google.com/terms/api-services-user-data-policy), including the Limited Use requirements.

**The built-in Google client.** "Google Drive Device Sign-in" can use a Google Cloud OAuth client provided by us. Using it does not send your data to us. Google's Cloud console shows the client's owner aggregate statistics, such as the number of API requests and error rates, but not the contents of your files. Administrators can configure their own Google Cloud client instead.

## Microsoft OneDrive

When a OneDrive destination is connected, the app requests the Microsoft Graph permissions `Files.ReadWrite` (read and write files in your OneDrive) and `offline_access` (stay connected without signing in each time). It uses them only to create its backup folder, upload backups, and list and delete old backups in that folder. The refresh token is stored encrypted on your Nextcloud server and is not shared with us or anyone else. OneDrive destinations always use the administrator's own Microsoft app registration.

## S3-compatible storage

For S3 destinations, the access key and secret entered by the administrator are stored encrypted on your Nextcloud server. They are used only to upload, list and delete backups at the configured endpoint.

## Encryption of backups

Administrators can encrypt backups with [age](https://age-encryption.org) public keys before upload. The private keys are never given to the app. Encrypted backups can only be read by holders of the matching private keys. Unencrypted backups can be read by anyone with access to the storage they are uploaded to.

## Retention and deletion

- **Backups** stay at the storage provider until they are deleted, either by the app's "Keep backups" setting or by you directly.
- **Stored credentials and tokens** are deleted from the Nextcloud database when the backup job is deleted. Deleting a job does not delete backups that were already uploaded.
- **To revoke access:**
  - Google: remove the app at <https://myaccount.google.com/permissions>.
  - Microsoft: remove it at <https://account.live.com/consent/Manage> (personal accounts) or <https://myapps.microsoft.com> (work or school accounts).

  Once access is revoked, the app can no longer reach your account.
- **Removing the app** from Nextcloud removes the app's code. Administrators should delete the backup jobs first to remove stored credentials.

## Third-party services

When you use them, the following providers process data under their own privacy policies:

- Google: <https://policies.google.com/privacy>
- Microsoft: <https://privacy.microsoft.com/privacystatement>
- your S3-compatible storage provider (see their policy).

## Children

The app is an administration tool for Nextcloud servers and is not directed at children.

## Changes to this policy

We will publish changes to this policy in the app's repository and update the effective date above. The full history of changes is in the repository's commit history.

## Contact

Questions about this policy: Jason Williams, <jasonw@tulg.org>, or open an issue at <https://github.com/uberlinuxguy/nextcloud-tagged-backup/issues>.
