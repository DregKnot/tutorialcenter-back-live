<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Assessment extends Model
{
    use SoftDeletes;

    const DRAFT = 'draft';
    const PUBLISHED = 'published';
    const CLOSED = 'closed';

    protected $fillable = [
        'class_id',
        'subject_id',
        'created_by',
        'title',
        'description',
        'instructions',
        'opens_at',
        'due_at',
        'status',
        'total_marks',
        'pass_mark',
        'timer_minutes',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'due_at' => 'datetime',
        'total_marks' => 'float',
        'pass_mark' => 'float',
        'timer_minutes' => 'integer',
    ];

    /**
     * The class (e.g. year group) this assessment was created for.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function class()
    {
        return $this->belongsTo(Classes::class, 'class_id');
    }

    /**
     * The subject this assessment is tagged to.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * The staff member (tutor) who created the assessment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function creator()
    {
        return $this->belongsTo(Staff::class, 'created_by');
    }

    /**
     * The questions attached to this assessment, kept in display order.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function questions()
    {
        return $this->hasMany(AssessmentQuestion::class)->orderBy('order');
    }

    /**
     * The student submissions recorded against this assessment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function submissions()
    {
        return $this->hasMany(AssessmentSubmission::class);
    }

    /**
     * Enrollments on the tagged subject that are eligible to attempt this.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function subjectEnrollments()
    {
        return $this->hasMany(SubjectsEnrollment::class, 'subject_id', 'subject_id');
    }

    /**
     * Students enrolled on the tagged subject for this assessment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function students()
    {
        return $this->hasManyThrough(
            Student::class,
            SubjectsEnrollment::class,
            'subject_id',
            'id',
            'subject_id',
            'student_id'
        );
    }
}
