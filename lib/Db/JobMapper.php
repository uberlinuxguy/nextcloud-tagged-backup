<?php

declare(strict_types=1);

namespace OCA\TaggedBackup\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<Job>
 */
class JobMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'tagged_backup_jobs', Job::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function find(int $id): Job {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return Job[]
	 */
	public function findAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('id');
		return $this->findEntities($qb);
	}

	/**
	 * Atomically mark a job as running. Returns false if another run holds
	 * the lock and it is not older than $staleBefore.
	 */
	public function tryLock(int $id, int $now, int $staleBefore): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('running_since', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('running_since'),
				$qb->expr()->lt('running_since', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_INT)),
			));
		return $qb->executeStatement() === 1;
	}

	public function unlock(int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('running_since', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
