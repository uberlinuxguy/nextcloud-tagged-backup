<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="tagged-backup" class="section"
	data-redirect-uri="<?php p($_['redirectUri']); ?>"
	data-has-bzip2="<?php p($_['hasBzip2'] ? '1' : '0'); ?>"
	data-has-google-device-client="<?php p($_['hasGoogleDeviceClient'] ? '1' : '0'); ?>">
	<h2><?php p($l->t('Tagged backup')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Back up every file and folder carrying one of the selected collaborative tags. Each backup is a tar archive with a manifest.json listing paths, sizes and SHA-256 checksums, optionally compressed and encrypted with age, uploaded to S3, Google Drive or OneDrive.')); ?>
	</p>
	<div id="tagged-backup-notice" class="tb-notice" hidden></div>
	<div class="tb-table-wrap">
	<table class="tb-jobs">
		<thead>
			<tr>
				<th><?php p($l->t('Name')); ?></th>
				<th><?php p($l->t('Tags')); ?></th>
				<th><?php p($l->t('Destination')); ?></th>
				<th><?php p($l->t('Schedule')); ?></th>
				<th><?php p($l->t('Last run')); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody id="tagged-backup-jobs">
			<tr><td colspan="6"><?php p($l->t('Loading…')); ?></td></tr>
		</tbody>
	</table>
	</div>
	<button id="tagged-backup-add" class="button primary"><?php p($l->t('Add backup job')); ?></button>
	<form id="tagged-backup-form" class="tb-form" hidden></form>
	<div id="tagged-backup-preview" class="tb-preview" hidden></div>
</div>
