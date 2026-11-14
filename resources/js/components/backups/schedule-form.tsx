import { Form } from '@inertiajs/react';
import { useEffect } from 'react';
import BackupScheduleController from '@/actions/App/Http/Admin/Tools/Backups/BackupScheduleController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { SelectField } from '@/components/ui/select-field';
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
            className="space-y-4"
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

                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor="schedule-hour">
                            Back up each night at
                        </Label>
                        <SelectField
                            id="schedule-hour"
                            name="values[hour]"
                            defaultValue={schedule.hour}
                            options={schedule.hours.map((hour) => ({
                                value: hour,
                                label: hour,
                            }))}
                            aria-describedby="schedule-hour-hint schedule-hour-error"
                        />
                        <p
                            id="schedule-hour-hint"
                            className="text-ink-2 text-xs"
                        >
                            In the site’s time zone, {schedule.zone}. Choose an
                            hour when few people visit.
                        </p>
                        <InputError
                            id="schedule-hour-error"
                            message={errors['values.hour']}
                        />
                    </div>

                    <p className="text-sm leading-9">
                        Keep a backup from each of the last{' '}
                        <Input
                            id="schedule-daily"
                            name="values[keep_daily]"
                            type="number"
                            min={1}
                            max={90}
                            defaultValue={schedule.keepDaily}
                            aria-label="Daily backups kept"
                            aria-describedby="schedule-daily-error"
                            className="inline-block w-20"
                        />{' '}
                        days, then one from each of{' '}
                        <Input
                            id="schedule-weekly"
                            name="values[keep_weekly]"
                            type="number"
                            min={0}
                            max={52}
                            defaultValue={schedule.keepWeekly}
                            aria-label="Weekly backups kept"
                            aria-describedby="schedule-weekly-error"
                            className="inline-block w-20"
                        />{' '}
                        weeks before them, and one from each of{' '}
                        <Input
                            id="schedule-monthly"
                            name="values[keep_monthly]"
                            type="number"
                            min={0}
                            max={120}
                            defaultValue={schedule.keepMonthly}
                            aria-label="Monthly backups kept"
                            aria-describedby="schedule-monthly-error"
                            className="inline-block w-20"
                        />{' '}
                        months before those.
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

                    <p className="text-ink-2 text-xs">
                        Older backups are removed once a week, never while a
                        backup runs. Keeping fewer removes the extra ones at the
                        next weekly removal.
                    </p>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="submit"
                            disabled={processing}
                            aria-describedby="schedule-version-error"
                            data-test="backup-schedule-save"
                        >
                            Save
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
