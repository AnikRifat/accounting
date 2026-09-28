<?php

namespace App\Enums;

/** Every business document the Sales module issues; all live in the `documents` table. */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case Quotation = 'quotation';
    case Estimate = 'estimate';
    case Proforma = 'proforma';
    case DeliveryNote = 'delivery_note';
    case CreditNote = 'credit_note';
    case PurchaseOrder = 'purchase_order';
    case Bill = 'bill';
    case DebitNote = 'debit_note';
    case Contract = 'contract';

    public function label(): string
    {
        return match ($this) {
            self::Invoice => __('Invoice'),
            self::Quotation => __('Quotation'),
            self::Estimate => __('Estimate'),
            self::Proforma => __('Proforma invoice'),
            self::DeliveryNote => __('Delivery note'),
            self::CreditNote => __('Credit note'),
            self::PurchaseOrder => __('Purchase order'),
            self::Bill => __('Bill'),
            self::DebitNote => __('Debit note'),
            self::Contract => __('Contract'),
        };
    }

    public function pluralLabel(): string
    {
        return match ($this) {
            self::Invoice => __('Invoices'),
            self::Quotation => __('Quotations'),
            self::Estimate => __('Estimates'),
            self::Proforma => __('Proforma invoices'),
            self::DeliveryNote => __('Delivery notes'),
            self::CreditNote => __('Credit notes'),
            self::PurchaseOrder => __('Purchase orders'),
            self::Bill => __('Bills'),
            self::DebitNote => __('Debit notes'),
            self::Contract => __('Contracts'),
        };
    }

    /** URL segment, e.g. purchase-orders. */
    public function slug(): string
    {
        return str_replace('_', '-', $this->value).'s';
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(str_replace('-', '_', substr($slug, 0, -1)));
    }

    public function defaultPrefix(): string
    {
        return match ($this) {
            self::Invoice => 'INV-',
            self::Quotation => 'QUO-',
            self::Estimate => 'EST-',
            self::Proforma => 'PRO-',
            self::DeliveryNote => 'DN-',
            self::CreditNote => 'CN-',
            self::PurchaseOrder => 'PO-',
            self::Bill => 'BILL-',
            self::DebitNote => 'DBN-',
            self::Contract => 'CON-',
        };
    }

    /** Documents that can be posted to the books (the "Post to accounts" switch). */
    public function isPostable(): bool
    {
        return in_array($this, [self::Invoice, self::Bill, self::CreditNote, self::DebitNote], true);
    }

    /** Documents that are paid: they carry payments and a due status. */
    public function isPayable(): bool
    {
        return $this === self::Invoice || $this === self::Bill;
    }

    /** Credit and debit notes reduce what an invoice or bill still owes. */
    public function isNote(): bool
    {
        return $this === self::CreditNote || $this === self::DebitNote;
    }

    /** Supplier-side documents: the party is a supplier and money goes out. */
    public function isPurchase(): bool
    {
        return in_array($this, [self::PurchaseOrder, self::Bill, self::DebitNote], true);
    }

    /** Documents with priced lines; a contract has a text body instead. */
    public function hasLines(): bool
    {
        return $this !== self::Contract;
    }

    /** Offers (quotation, estimate, proforma) can be accepted or declined and converted to an invoice. */
    public function isOffer(): bool
    {
        return in_array($this, [self::Quotation, self::Estimate, self::Proforma], true);
    }

    /** The type a note settles, or the type a document converts into. */
    public function convertsTo(): ?self
    {
        return match ($this) {
            self::Quotation, self::Estimate, self::Proforma => self::Invoice,
            self::PurchaseOrder => self::Bill,
            default => null,
        };
    }

    /** For a note: the document type it is issued against. */
    public function noteFor(): ?self
    {
        return match ($this) {
            self::CreditNote => self::Invoice,
            self::DebitNote => self::Bill,
            default => null,
        };
    }

    /** The note issued against this type (invoice → credit note, bill → debit note). */
    public function note(): ?self
    {
        return match ($this) {
            self::Invoice => self::CreditNote,
            self::Bill => self::DebitNote,
            default => null,
        };
    }

    /** Label of the date after which the document lapses or falls due. */
    public function dueLabel(): ?string
    {
        return match (true) {
            $this->isPayable() => __('Due date'),
            $this->isOffer() => __('Valid until'),
            $this === self::PurchaseOrder => __('Expected delivery'),
            $this === self::Contract => __('Ends on'),
            default => null,
        };
    }
}
