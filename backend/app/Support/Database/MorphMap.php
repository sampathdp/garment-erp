<?php

declare(strict_types=1);

namespace App\Support\Database;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\BulkProduction\Models\BulkAssignment;
use App\Modules\BulkProduction\Models\BulkJob;
use App\Modules\BulkProduction\Models\BulkJobAssignment;
use App\Modules\BulkProduction\Models\BulkJobDocument;
use App\Modules\BulkProduction\Models\BulkJobMeasurement;
use App\Modules\BulkProduction\Models\BulkJobMeasurementChange;
use App\Modules\BulkProduction\Models\BulkMeasurement;
use App\Modules\BulkProduction\Models\BulkMeasurementField;
use App\Modules\BulkProduction\Models\BulkReview;
use App\Modules\BulkProduction\Models\BulkVersion;
use App\Modules\BulkProduction\Models\BulkVersionFile;
use App\Modules\BulkProduction\Models\ChecklistRun;
use App\Modules\BulkProduction\Models\ChecklistRunItem;
use App\Modules\BulkProduction\Models\ChecklistTemplate;
use App\Modules\BulkProduction\Models\ChecklistTemplateItem;
use App\Modules\BulkProduction\Models\MeasurementPoint;
use App\Modules\BulkProduction\Models\ProductionIssue;
use App\Modules\BulkProduction\Models\ProductionIssueEvent;
use App\Modules\BulkProduction\Models\ProductionUnit;
use App\Modules\BulkProduction\Models\SizeSpec;
use App\Modules\BulkProduction\Models\Supplier;
use App\Modules\Customers\Models\Customer;
use App\Modules\Cutting\Models\CuttingJob;
use App\Modules\Cutting\Models\CuttingJobAssignment;
use App\Modules\Cutting\Models\CuttingJobEvent;
use App\Modules\Cutting\Models\CuttingJobQuantity;
use App\Modules\Delivery\Models\Delivery;
use App\Modules\Delivery\Models\DeliveryDocument;
use App\Modules\Design\Models\DesignCorrectionRequest;
use App\Modules\Design\Models\DesignReview;
use App\Modules\Design\Models\DesignTask;
use App\Modules\Design\Models\DesignVersion;
use App\Modules\Design\Models\DesignVersionFile;
use App\Modules\Documents\Models\Document;
use App\Modules\Garments\Models\GarmentFieldDefinition;
use App\Modules\Garments\Models\GarmentList;
use App\Modules\Garments\Models\GarmentListItem;
use App\Modules\Garments\Models\GarmentListItemValue;
use App\Modules\Garments\Models\GarmentListVersion;
use App\Modules\Garments\Models\GarmentOption;
use App\Modules\Garments\Models\GarmentOptionSet;
use App\Modules\GatePass\Models\GatePass;
use App\Modules\GatePass\Models\GatePassLine;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Invoices\Models\Invoice;
use App\Modules\Invoices\Models\InvoiceItem;
use App\Modules\Notifications\Models\NotificationOutbox;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderItemOption;
use App\Modules\Orders\Models\OrderStatusHistory;
use App\Modules\Orders\Models\OrderType;
use App\Modules\Orders\Models\UrgentApproval;
use App\Modules\Orders\Models\UrgentApprovalDecision;
use App\Modules\Organizations\Models\AppSetting;
use App\Modules\Organizations\Models\Branch;
use App\Modules\Organizations\Models\Holiday;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\Page;
use App\Modules\Organizations\Models\WorkingCalendarDay;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\PaymentVerificationEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Pricing\Models\Material;
use App\Modules\Pricing\Models\PriceList;
use App\Modules\Pricing\Models\PriceListItem;
use App\Modules\Pricing\Models\PriceListItemCondition;
use App\Modules\Pricing\Models\PriceListOptionPrice;
use App\Modules\Pricing\Models\ProductOption;
use App\Modules\Pricing\Models\ProductType;
use App\Modules\Pricing\Models\Size;
use App\Modules\Pricing\Models\SizeChart;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Printing\Models\PrintJobEvent;
use App\Modules\Printing\Models\PrintJobGatePass;
use App\Modules\Printing\Models\PrintSample;
use App\Modules\Printing\Models\PrintSampleDocument;
use App\Modules\QualityControl\Models\DefectType;
use App\Modules\QualityControl\Models\QcDefect;
use App\Modules\QualityControl\Models\QcInspection;
use App\Modules\Sewing\Models\SewingJob;
use Illuminate\Database\Eloquent\Model;

/**
 * Morph aliases stored in polymorphic columns (audit_logs.subject_type, notifications,
 * Spatie pivots). Never rename an alias once data exists. Regenerate when adding models.
 */
final class MorphMap
{
    /** @return array<string, class-string<Model>> */
    public static function map(): array
    {
        return [
            'app_setting' => AppSetting::class,
            'audit_log' => AuditLog::class,
            'branch' => Branch::class,
            'bulk_assignment' => BulkAssignment::class,
            'bulk_job' => BulkJob::class,
            'bulk_job_assignment' => BulkJobAssignment::class,
            'bulk_job_document' => BulkJobDocument::class,
            'bulk_job_measurement' => BulkJobMeasurement::class,
            'bulk_job_measurement_change' => BulkJobMeasurementChange::class,
            'bulk_measurement' => BulkMeasurement::class,
            'bulk_measurement_field' => BulkMeasurementField::class,
            'bulk_review' => BulkReview::class,
            'bulk_version' => BulkVersion::class,
            'bulk_version_file' => BulkVersionFile::class,
            'checklist_run' => ChecklistRun::class,
            'checklist_run_item' => ChecklistRunItem::class,
            'checklist_template' => ChecklistTemplate::class,
            'checklist_template_item' => ChecklistTemplateItem::class,
            'customer' => Customer::class,
            'cutting_job' => CuttingJob::class,
            'cutting_job_assignment' => CuttingJobAssignment::class,
            'cutting_job_event' => CuttingJobEvent::class,
            'cutting_job_quantity' => CuttingJobQuantity::class,
            'defect_type' => DefectType::class,
            'delivery' => Delivery::class,
            'delivery_document' => DeliveryDocument::class,
            'notification_outbox' => NotificationOutbox::class,
            'design_correction_request' => DesignCorrectionRequest::class,
            'design_review' => DesignReview::class,
            'design_task' => DesignTask::class,
            'design_version' => DesignVersion::class,
            'design_version_file' => DesignVersionFile::class,
            'document' => Document::class,
            'garment_field_definition' => GarmentFieldDefinition::class,
            'garment_list' => GarmentList::class,
            'garment_list_item' => GarmentListItem::class,
            'garment_list_item_value' => GarmentListItemValue::class,
            'garment_list_version' => GarmentListVersion::class,
            'garment_option' => GarmentOption::class,
            'garment_option_set' => GarmentOptionSet::class,
            'gate_pass' => GatePass::class,
            'gate_pass_line' => GatePassLine::class,
            'holiday' => Holiday::class,
            'invoice' => Invoice::class,
            'invoice_item' => InvoiceItem::class,
            'material' => Material::class,
            'measurement_point' => MeasurementPoint::class,
            'order' => Order::class,
            'order_item' => OrderItem::class,
            'order_item_option' => OrderItemOption::class,
            'order_status_history' => OrderStatusHistory::class,
            'order_type' => OrderType::class,
            'organization' => Organization::class,
            'page' => Page::class,
            'payment' => Payment::class,
            'payment_verification_event' => PaymentVerificationEvent::class,
            'price_list' => PriceList::class,
            'price_list_item' => PriceListItem::class,
            'price_list_item_condition' => PriceListItemCondition::class,
            'price_list_option_price' => PriceListOptionPrice::class,
            'print_job' => PrintJob::class,
            'print_job_event' => PrintJobEvent::class,
            'print_job_gate_pass' => PrintJobGatePass::class,
            'print_sample' => PrintSample::class,
            'print_sample_document' => PrintSampleDocument::class,
            'product_option' => ProductOption::class,
            'product_type' => ProductType::class,
            'production_issue' => ProductionIssue::class,
            'production_issue_event' => ProductionIssueEvent::class,
            'production_unit' => ProductionUnit::class,
            'qc_defect' => QcDefect::class,
            'qc_inspection' => QcInspection::class,
            'refund' => Refund::class,
            'sewing_job' => SewingJob::class,
            'size' => Size::class,
            'size_chart' => SizeChart::class,
            'size_spec' => SizeSpec::class,
            'supplier' => Supplier::class,
            'urgent_approval' => UrgentApproval::class,
            'urgent_approval_decision' => UrgentApprovalDecision::class,
            'role' => Role::class,
            'user' => User::class,
            'working_calendar_day' => WorkingCalendarDay::class,
        ];
    }
}
