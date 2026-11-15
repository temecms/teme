import { Form } from '@inertiajs/react';
import { useEffect } from 'react';
import BackupScheduleController from '@/actions/App/Http/Admin/Tools/Backups/BackupScheduleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { SelectField } from '@/components/ui/select-field';
import { __ } from '@/lib/i18n';
import type { BackupSchedule } from '@/types/backups';

/**
 * The hour and what's kept, changed in place beside the next backup
 * (I-361): the hour as a select of the twenty-four, and the three numbers
 * inside the sentence they make.
 */
export function ScheduleForm({
    schedule,
    onClose,
}: {
    schedule: BackupSchedule;
    onClose: () => void;
}) {
    useEffect(() => {
        document.getElementById('schedule-hour')?.focus();
    }, []);

    return (
        <Form
            {...BackupScheduleController.form()}
            options={{ preserveScroll: true }}
            onSuccess={onClose}
            className="grid w-full gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="expected_version"
                        value={schedule.version}
                    />
                    <InputError
                        id="schedule-version-error"
                        message={errors.expected_version}
                    />

                    <Field
                        htmlFor="schedule-hour"
                        label={__('Back up each night at')}
                        help={__(
                            'In the site’s time zone, :zone. Choose an hour when few people visit.',
                            { zone: schedule.zone },
                        )}
                        error={errors['values.hour']}
                        className="max-w-80"
                    >
                        <SelectField
                            id="schedule-hour"
                            name="values[hour]"
                            defaultValue={schedule.hour}
                            options={schedule.hours.map((hour) => ({
                                value: hour,
                                label: hour,
                            }))}
                        />
                    </Field>

                    <p className="text-body flex flex-wrap items-center gap-2">
                        {__('Keep a backup from each of the last')}{' '}
                        <Input
                            id="schedule-daily"
                            name="values[keep_daily]"
                            type="number"
                            min={1}
                            max={90}
                            defaultValue={schedule.keepDaily}
                            aria-label={__('Daily backups kept')}
                            aria-describedby="schedule-daily-error"
                            className="inline-block w-20"
                        />{' '}
                        {__('days, then one from each of')}{' '}
                        <Input
                            id="schedule-weekly"
                            name="values[keep_weekly]"
                            type="number"
                            min={0}
                            max={52}
                            defaultValue={schedule.keepWeekly}
                            aria-label={__('Weekly backups kept')}
                            aria-describedby="schedule-weekly-error"
                            className="inline-block w-20"
                        />{' '}
                        {__('weeks before them, and one from each of')}{' '}
                        <Input
                            id="schedule-monthly"
                            name="values[keep_monthly]"
                            type="number"
                            min={0}
                            max={120}
                            defaultValue={schedule.keepMonthly}
                            aria-label={__('Monthly backups kept')}
                            aria-describedby="schedule-monthly-error"
                            className="inline-block w-20"
                        />{' '}
                        {__('months before those.')}
                    </p>
                    <InputError
                        id="schedule-daily-error"
                        message={errors['values.keep_daily']}
                    />
                    <InputError
                        id="schedule-weekly-error"
                        message={errors['values.keep_weekly']}
                    />
                    <InputError
                        id="schedule-monthly-error"
                        message={errors['values.keep_monthly']}
                    />

                    <p className="text-caption text-ink-3">
                        {__(
                            'Older backups are removed once a week, never while a backup runs. Keeping fewer removes the extra ones at the next weekly removal.',
                        )}
                    </p>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            aria-describedby="schedule-version-error"
                            data-test="backup-schedule-save"
                        >
                            {__('Save')}
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            {__('Cancel')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
