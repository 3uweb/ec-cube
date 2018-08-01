<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) LOCKON CO.,LTD. All Rights Reserved.
 *
 * http://www.lockon.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eccube\Repository;

use Doctrine\ORM\NoResultException;
use Eccube\Common\EccubeConfig;
use Eccube\Entity\BaseInfo;
use Eccube\Entity\Customer;
use Eccube\Entity\TaxRule;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * TaxRuleRepository
 *
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class TaxRuleRepository extends AbstractRepository
{
    private $rules = [];

    /**
     * @var BaseInfo
     */
    protected $baseInfo;

    /**
     * @var AuthorizationCheckerInterface
     */
    protected $authorizationChecker;

    /**
     * @var TokenStorageInterface
     */
    protected $tokenStorage;

    /**
     * TaxRuleRepository constructor.
     *
     * @param RegistryInterface $registry
     * @param TokenStorageInterface $tokenStorage
     * @param AuthorizationCheckerInterface $authorizationChecker
     * @param BaseInfo $baseInfo
     * @param EccubeConfig $eccubeConfig
     */
    public function __construct(
        RegistryInterface $registry,
        TokenStorageInterface $tokenStorage,
        AuthorizationCheckerInterface $authorizationChecker,
        BaseInfo $baseInfo,
        EccubeConfig $eccubeConfig
    ) {
        parent::__construct($registry, TaxRule::class);
        $this->tokenStorage = $tokenStorage;
        $this->authorizationChecker = $authorizationChecker;
        $this->baseInfo = $baseInfo;
        $this->eccubeConfig = $eccubeConfig;
    }

    public function newTaxRule()
    {
        $TaxRule = new \Eccube\Entity\TaxRule();
        $RoundingType = $this->getEntityManager()
            ->getRepository('Eccube\Entity\Master\RoundingType')
            ->find(1);
        $TaxRule->setRoundingType($RoundingType);
        $TaxRule->setTaxAdjust(0);

        return $TaxRule;
    }

    /**
     * 現在有効な税率設定情報を返す
     *
     * @param  int|null|\Eccube\Entity\Product        $Product      商品
     * @param  int|null|\Eccube\Entity\ProductClass   $ProductClass 商品規格
     * @param  int|null|\Eccube\Entity\Master\Pref    $Pref         都道府県
     * @param  int|null|\Eccube\Entity\Master\Country $Country      国
     *
     * @return \Eccube\Entity\TaxRule                 税設定情報
     *
     * @throws NoResultException
     */
    public function getByRule($Product = null, $ProductClass = null, $Pref = null, $Country = null)
    {
        // Pref Country 設定
        if (!$Pref && !$Country && $this->tokenStorage->getToken() && $this->authorizationChecker->isGranted('ROLE_USER')) {
            /* @var $Customer \Eccube\Entity\Customer */
            $Customer = $this->tokenStorage->getToken()->getUser();
            // FIXME なぜか管理画面でも実行されている.
            if ($Customer instanceof Customer) {
                $Pref = $Customer->getPref();
                $Country = $Customer->getCountry();
            }
        }

        // 商品単位税率設定がOFFの場合
        if (!$this->baseInfo->isOptionProductTaxRule()) {
            $Product = null;
            $ProductClass = null;
        }

        // Cache Key 設定
        if ($Product instanceof \Eccube\Entity\Product) {
            $productId = $Product->getId();
        } elseif ($Product) {
            $productId = $Product;
        } else {
            $productId = '0';
        }
        if ($ProductClass instanceof \Eccube\Entity\ProductClass) {
            $productClassId = $ProductClass->getId();
        } elseif ($ProductClass) {
            $productClassId = $ProductClass;
        } else {
            $productClassId = '0';
        }
        if ($Pref instanceof \Eccube\Entity\Master\Pref) {
            $prefId = $Pref->getId();
        } elseif ($Pref) {
            $prefId = $Pref;
        } else {
            $prefId = '0';
        }
        if ($Country instanceof \Eccube\Entity\Master\Country) {
            $countryId = $Country->getId();
        } elseif ($Country) {
            $countryId = $Country;
        } else {
            $countryId = '0';
        }
        $cacheKey = $productId.':'.$productClassId.':'.$prefId.':'.$countryId;

        // すでに取得している場合はキャッシュから
        if (isset($this->rules[$cacheKey])) {
            return $this->rules[$cacheKey];
        }

        $parameters = [];
        $qb = $this->createQueryBuilder('t')
            ->where('t.apply_date < :apply_date');
        $parameters[':apply_date'] = new \DateTime();

        // Pref
        if ($Pref) {
            $qb->andWhere('t.Pref IS NULL OR t.Pref = :Pref');
            $parameters['Pref'] = $Pref;
        } else {
            $qb->andWhere('t.Pref IS NULL');
        }

        // Country
        if ($Country) {
            $qb->andWhere('t.Country IS NULL OR t.Country = :Country');
            $parameters['Country'] = $Country;
        } else {
            $qb->andWhere('t.Country IS NULL');
        }

        /*
         * Product, ProductClass が persist される前に TaxRuleEventSubscriber によってアクセスされる
         * 場合があるため、ID の存在もチェックする.
         * https://github.com/EC-CUBE/ec-cube/issues/677
         */

        // Product
        if ($Product && $productId > 0) {
            $qb->andWhere('t.Product IS NULL OR t.Product = :Product');
            $parameters['Product'] = $Product;
        } else {
            $qb->andWhere('t.Product IS NULL');
        }

        // ProductClass
        if ($ProductClass && $productClassId > 0) {
            $qb->andWhere('t.ProductClass IS NULL OR t.ProductClass = :ProductClass');
            $parameters['ProductClass'] = $ProductClass;
        } else {
            $qb->andWhere('t.ProductClass IS NULL');
        }

        $TaxRules = $qb
            ->setParameters($parameters)
            ->orderBy('t.apply_date', 'DESC') // 実際は usort() でソートする
            ->getQuery()
            ->getResult();

        // 地域設定を優先するが、システムパラメーターなどに設定を持っていくか
        // 後に書いてあるほど優先される
        $priorityKeys = [];
        foreach ($this->eccubeConfig['eccube_tax_rule_priority'] as $priorityKey) {
            $priorityKeys[] = str_replace('_', '', preg_replace('/_id\z/', '', $priorityKey));
        }

        foreach ($TaxRules as $TaxRule) {
            $sortNo = 0;
            foreach ($priorityKeys as $index => $key) {
                $arrayProperties = array_change_key_case($TaxRule->toArray());
                if ($arrayProperties[$key]) {
                    // 配列の数値添字を重みとして利用する
                    $sortNo += 1 << ($index + 1);
                }
            }
            $TaxRule->setSortNo($sortNo);
        }

        // 適用日降順, sortNo 降順にソートする
        usort($TaxRules, function ($a, $b) {
            return $a->compareTo($b);
        });

        if (!empty($TaxRules)) {
            $this->rules[$cacheKey] = $TaxRules[0];

            return $TaxRules[0];
        } else {
            throw new NoResultException();
        }
    }

    /**
     * getList
     *
     * @return array|null
     */
    public function getList()
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.apply_date', 'DESC')
            ->where('t.Product IS NULL AND t.ProductClass IS NULL');
        $TaxRules = $qb
            ->getQuery()
            ->getResult();

        return $TaxRules;
    }

    /**
     * getById
     *
     * @deprecated Use TaxRuleRepository::find()
     *
     * @param  int   $id
     *
     * @return array
     */
    public function getById($id)
    {
        $criteria = [
            'id' => $id,
        ];

        return $this->findOneBy($criteria);
    }

    /**
     * getByTime
     *
     * @deprecated Use magic finder methods. TaxRuleRepository::findOneByApplyDate()
     *
     * @param  string $applyDate
     *
     * @return mixed
     */
    public function getByTime($applyDate)
    {
        $criteria = [
            'apply_date' => $applyDate,
        ];

        return $this->findOneBy($criteria);
    }

    /**
     * 税規約の削除.
     *
     * @param  int|\Eccube\Entity\TaxRule $TaxRule 税規約
     *
     * @throws NoResultException
     */
    public function delete($TaxRule)
    {
        if (!$TaxRule instanceof \Eccube\Entity\TaxRule) {
            $TaxRule = $this->find($TaxRule);
        }
        if (!$TaxRule) {
            throw new NoResultException();
        }
        $em = $this->getEntityManager();
        $em->remove($TaxRule);
        $em->flush();
    }

    /**
     * TaxRule のキャッシュをクリアする.
     *
     * getByRule() をコールすると、結果をキャッシュし、2回目以降はデータベースへアクセスしない.
     * このメソッドをコールすると、キャッシュをクリアし、再度データベースを参照して結果を取得する.
     */
    public function clearCache()
    {
        $this->rules = [];
    }
}
