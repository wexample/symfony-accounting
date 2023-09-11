<?php
/**
 * Created by PhpStorm.
 * User: weeger
 * Date: 13/10/18
 * Time: 17:41.
 */

namespace Wexample\SymfonyAccounting\Entity\Traits;

use Doctrine\ORM\Mapping\Column;
use Symfony\Component\Validator\Constraints\Length;
use function bcmod;
use function is_numeric;
use function ord;
use function pow;
use function str_pad;
use function strlen;
use function substr;

trait FrBankInfo2018Trait
{
    #[Length(max: 100)]
    #[Column(type: 'string', length: 100, nullable: true)]
    private ?string $bank_owner = null;

    #[Length(max: 34)]
    #[Column(type: 'string', length: 34, nullable: true)]
    private ?string $bank_iban = null;

    #[Length(max: 11)]
    #[Column(type: 'string', length: 11, nullable: true)]
    private ?string $bank_bic = null;

    #[Length(max: 100)]
    #[Column(type: 'string', length: 100, nullable: true)]
    private ?string $bank_location = null;

    #[Length(max: 5)]
    #[Column(type: 'string', length: 5, nullable: true)]
    private ?string $bank_rib_bank = null;

    #[Length(max: 5)]
    #[Column(type: 'string', length: 5, nullable: true)]
    private ?string $bank_rib_agency = null;

    #[Length(max: 11)]
    #[Column(type: 'string', length: 11, nullable: true)]
    private ?string $bank_rib_account = null;

    /**
     * Should be of type Assert\Range(
     *      min = 1,
     *      max = 97
     * ), but allow null.
     */
    #[Column(type: 'integer', nullable: true)]
    private ?int $bank_rib_key = null;

    public function getBankOwner(): ?string
    {
        return $this->bank_owner;
    }

    public function setBankOwner(?string $bank_owner): self
    {
        $this->bank_owner = $bank_owner;

        return $this;
    }

    public function getBankIban(): ?string
    {
        return $this->bank_iban;
    }

    public function setBankIban(?string $bank_iban): self
    {
        $this->bank_iban = $bank_iban;

        return $this;
    }

    public function getBankBic(): ?string
    {
        return $this->bank_bic;
    }

    public function setBankBic(?string $bank_bic): self
    {
        $this->bank_bic = $bank_bic;

        return $this;
    }

    public function getBankLocation(): ?string
    {
        return $this->bank_location;
    }

    public function setBankLocation(?string $bank_location): self
    {
        $this->bank_location = $bank_location;

        return $this;
    }

    public function getBankRibBank(): ?string
    {
        return $this->bank_rib_bank;
    }

    public function setBankRibBank(?string $bank_rib_bank): self
    {
        $this->bank_rib_bank = $bank_rib_bank;

        return $this;
    }

    public function getBankRibAgency(): ?string
    {
        return $this->bank_rib_agency;
    }

    public function setBankRibAgency(?string $bank_rib_agency): self
    {
        $this->bank_rib_agency = $bank_rib_agency;

        return $this;
    }

    public function getBankRibAccount(): ?string
    {
        return $this->bank_rib_account;
    }

    public function setBankRibAccount(?string $bank_rib_account): self
    {
        $this->bank_rib_account = $bank_rib_account;

        return $this;
    }

    public function getBankRibKey(): ?string
    {
        return str_pad((int) $this->bank_rib_key, 2, '0', STR_PAD_LEFT);
    }

    public function setBankRibKey($bank_rib_key): self
    {
        $this->bank_rib_key = (int) $bank_rib_key;

        return $this;
    }

    /**
     * @see https://fr.wikipedia.org/wiki/Basic_Bank_Account_Number#V%C3%A9rification_du_RIB_en_PHP
     */
    public function ribValidate(
        $bank,
        $agency,
        $account,
        $key
    ): bool {
        $tab = '';
        $len = strlen($account);
        if (11 != $len) {
            return false;
        }

        for ($i = 0; $i < $len; ++$i) {
            $car = substr($account, $i, 1);
            if (!is_numeric($car)) {
                $c = ord($car) - (ord('A') - 1);
                $b = (($c + pow(
                                2,
                                ($c - 10) / 9
                            )) % 10) + (($c > 18 && $c < 25) ? 1 : 0);
                $tab .= $b;
            } else {
                $tab .= $car;
            }
        }

        $int = $bank.$agency.$tab.$key;

        return strlen($int) >= 21 && 0 == bcmod($int, 97);
    }
}
