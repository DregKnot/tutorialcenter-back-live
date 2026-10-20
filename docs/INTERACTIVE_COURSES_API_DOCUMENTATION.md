# Interactive Courses & Animated Video Lessons — Frontend Integration Guide

This guide is designed for frontend developers building the web or mobile user interface for the **Interactive Courses** feature in TutorialCenter.

---

## 1. Feature Architecture & Concepts

The course system is structured as a clear hierarchical learning path:

```
Subject (e.g., Mathematics)
  └── Module (e.g., Module 1: Differential Calculus)
        └── Lesson (e.g., Lesson 1.1: Limits and Continuity)
              ├── Animated Video & Materials (Optional: MP4, YouTube, PDF, Docs)
              └── Practice Quizzes (Optional: Multiple Choice, True/False, Short Answer)
```

### Core Rules for Frontend:
1. **Lessons can have multiple quizzes and materials** — both are optional.
2. **Video Lessons** can be uploaded files (streamed from backend) or external video links (YouTube, Vimeo, Cloudflare Stream).
3. **Progress is tracked automatically**:
   - Heartbeat updates tracking watch time (`time_spent_seconds`) and scroll/video progress (`completion_percentage`).
   - Dedicated completion action when video finishes or student clicks "Mark as Complete".
4. **Quizzes are self-contained**:
   - Independent from live class assessments.
   - Built with timer support, maximum attempts, pass marks, and instant auto-grading for objective questions.

---

## 2. Authentication & Base Headers

All API endpoints expect the standard JSON headers:

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer {token}
```

- **Student Endpoints**: Use the student bearer token (`auth:student`).
- **Admin / Tutor Endpoints**: Use the staff bearer token (`auth:staff`).

---

## 3. Student Learning Journey (Student Web/App UI)

### 3.1 Course Curriculum & Progress Overview

Fetch the full curriculum for a subject with the student's progress and completion percentage in a single call.

- **Endpoint**: `GET /api/students/courses/subjects/{subjectId}/progress`
- **Use Case**: Render the course overview page, module accordion, progress bars, and completion badges.

#### Response:
```json
{
  "status": "success",
  "progress": {
    "subject_id": 3,
    "subject_name": "General Mathematics",
    "total_lessons": 12,
    "completed_lessons": 5,
    "in_progress_lessons": 2,
    "overall_percentage": 41.7,
    "modules": [
      {
        "id": 1,
        "title": "Module 1: Algebra Fundamentals",
        "order": 1,
        "total_lessons": 4,
        "completed_lessons": 4,
        "percentage": 100,
        "lessons": [
          {
            "id": 10,
            "title": "Indices and Logarithms",
            "order": 1,
            "duration_minutes": 25,
            "progress_status": "completed",
            "completion_percentage": 100,
            "time_spent_seconds": 1500,
            "completed_at": "2026-10-19T20:15:00Z"
          }
        ]
      }
    ]
  }
}
```

---

### 3.2 Loading a Lesson (Video Player & Lesson Materials)

When a student selects a lesson, fetch the complete lesson data including materials and available quizzes.

- **Endpoint**: `GET /api/students/courses/lessons/{lessonId}`

#### Response:
```json
{
  "status": "success",
  "lesson": {
    "id": 10,
    "module_id": 1,
    "title": "Indices and Logarithms",
    "description": "Master index laws, zero indices, and negative powers with animated examples.",
    "duration_minutes": 25,
    "order": 1,
    "materials": [
      {
        "id": 5,
        "title": "Animated Lesson Video",
        "type": "video",
        "url": "https://www.youtube.com/embed/dQw4w9WgXcQ",
        "file_url": "https://api.tutorialcenter.ng/storage/lesson-materials/10/video.mp4",
        "duration_seconds": 1200,
        "order": 1,
        "is_preview": false
      },
      {
        "id": 6,
        "title": "Lesson Summary & Formulas (PDF)",
        "type": "document",
        "file_url": "https://api.tutorialcenter.ng/api/lesson-materials/6/download",
        "order": 2
      }
    ],
    "quizzes": [
      {
        "id": 2,
        "title": "Quick Check: Index Laws",
        "description": "5-question practice test",
        "total_marks": 5,
        "pass_mark": 60,
        "timer_minutes": 10,
        "max_attempts": 3
      }
    ]
  },
  "progress": {
    "status": "in_progress",
    "completion_percentage": 45,
    "time_spent_seconds": 540
  }
}
```

#### Frontend Implementation Note:
- If `material.type === 'video'` and `material.file_url` exists, load inside a standard HTML5 `<video controls src={material.file_url} />`.
- If `material.url` is a YouTube/Vimeo link, render inside an `<iframe>`.
- For downloadable documents, redirect or download using `material.file_url` or `GET /api/lesson-materials/{materialId}/download`.

---

### 3.3 Tracking Student Progress & Heartbeat

Keep track of watch time and progress while the student watches the video.

#### Periodic Heartbeat (Every 15–30 seconds):
- **Endpoint**: `POST /api/students/courses/lessons/{lessonId}/progress`

```json
{
  "time_spent_seconds": 30,
  "completion_percentage": 50,
  "status": "in_progress"
}
```

#### Marking as Completed:
Triggered automatically when the video reaches `onEnded` or when student clicks "Mark Completed":
- **Endpoint**: `POST /api/students/courses/lessons/{lessonId}/complete`
- **Body**: `{}`

---

### 3.4 Taking a Lesson Quiz

#### 1. List quizzes for a lesson with student's best score:
- **Endpoint**: `GET /api/students/courses/quizzes/lesson/{lessonId}`

#### 2. Open Quiz (Load questions without answers):
- **Endpoint**: `GET /api/students/courses/quizzes/{quizId}`
- Returns question prompts, options, and previous attempt info. Notice `is_correct` is **hidden** to prevent cheating.

#### 3. Start a new attempt:
- **Endpoint**: `POST /api/students/courses/quizzes/{quizId}/start`
- **Response**:
```json
{
  "status": "success",
  "message": "Quiz attempt started",
  "submission": {
    "id": 44,
    "quiz_id": 2,
    "attempt_number": 1,
    "status": "in_progress",
    "started_at": "2026-10-20T17:00:00Z"
  }
}
```

#### 4. Submit Quiz Answers (Auto-scoring):
- **Endpoint**: `POST /api/students/courses/quizzes/{quizId}/submissions/{submissionId}`
- **Payload**:
```json
{
  "answers": [
    {
      "question_id": 1,
      "selected_option_id": 4
    },
    {
      "question_id": 2,
      "selected_option_id": 7
    },
    {
      "question_id": 3,
      "text_answer": "Laws of Exponents"
    }
  ]
}
```

- **Response**:
```json
{
  "status": "success",
  "message": "Quiz submitted successfully",
  "submission": {
    "id": 44,
    "score": 4,
    "percentage": 80,
    "passed": true,
    "status": "submitted",
    "submitted_at": "2026-10-20T17:08:25Z"
  }
}
```

#### 5. Review Past Attempts:
- **Endpoint**: `GET /api/students/courses/quizzes/{quizId}/submissions`

---

## 4. Admin & Tutor Course Management (CMS)

Staff members (Tutors, Admins, Moderators) manage course content via `api/admin/...` or `api/tutor/...`.

### 4.1 Modules Management

| Method | Endpoint | Description | Payload / Notes |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/admin/modules/subject/{subjectId}` | List modules under subject | Returns module list with lesson counts |
| `POST` | `/api/admin/modules/subject/{subjectId}` | Create new module | `{ "title": "Module 1", "description": "...", "order": 1 }` |
| `GET` | `/api/admin/modules/{id}` | View module details & lessons | Returns module with lessons |
| `PUT` | `/api/admin/modules/{id}` | Update module | `{ "title": "Updated title", "order": 2 }` |
| `POST` | `/api/admin/modules/{id}/publish` | Publish module to students | `{}` |
| `POST` | `/api/admin/modules/{id}/archive` | Archive module (hide from students) | `{}` |
| `DELETE`| `/api/admin/modules/{id}` | Soft delete module | `{}` |
| `POST` | `/api/admin/modules/{id}/restore` | Restore deleted module | `{}` |
| `POST` | `/api/admin/modules/subject/{subjectId}/reorder` | Drag-and-drop reordering | `{ "ordered_ids": [3, 1, 2] }` |

---

### 4.2 Lessons Management

| Method | Endpoint | Description | Payload / Notes |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/admin/lessons/module/{moduleId}` | List lessons in module | Returns lessons with materials/quizzes counts |
| `POST` | `/api/admin/lessons/module/{moduleId}` | Create lesson | `{ "title": "Lesson 1", "duration_minutes": 30, "order": 1 }` |
| `GET` | `/api/admin/lessons/{id}` | Get lesson details | Includes materials, quizzes, questions |
| `PUT` | `/api/admin/lessons/{id}` | Update lesson | `{ "title": "...", "duration_minutes": 45 }` |
| `POST` | `/api/admin/lessons/{id}/publish` | Publish lesson | `{}` |
| `POST` | `/api/admin/lessons/{id}/archive` | Archive lesson | `{}` |
| `DELETE`| `/api/admin/lessons/{id}` | Soft delete lesson | `{}` |
| `POST` | `/api/admin/lessons/module/{moduleId}/reorder` | Reorder lessons | `{ "ordered_ids": [12, 10, 11] }` |

---

### 4.3 Lesson Materials Management (Videos, PDFs, Embeds)

| Method | Endpoint | Description | Payload / Notes |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/admin/lesson-materials/lesson/{lessonId}` | List materials | |
| `POST` | `/api/admin/lesson-materials/lesson/{lessonId}` | Add material | `multipart/form-data` or JSON (see below) |
| `PUT` | `/api/admin/lesson-materials/{id}` | Update material | `{ "title": "...", "is_preview": true }` |
| `DELETE`| `/api/admin/lesson-materials/{id}` | Remove material | Also deletes file from storage |
| `POST` | `/api/admin/lesson-materials/lesson/{lessonId}/reorder` | Reorder materials | `{ "ordered_ids": [5, 4, 6] }` |

#### Adding Material with File Upload (`multipart/form-data`):
```http
POST /api/admin/lesson-materials/lesson/{lessonId}
Content-Type: multipart/form-data

title: Animated Intro Video
type: video
file: (binary file: mp4/pdf/webm, max 100MB)
duration_seconds: 900
is_preview: false
```

#### Adding Material via External Link (YouTube, Vimeo, Google Drive):
```http
POST /api/admin/lesson-materials/lesson/{lessonId}
Content-Type: application/json

{
  "title": "Animated Lesson Stream",
  "type": "video",
  "url": "https://www.youtube.com/watch?v=example",
  "duration_seconds": 1200,
  "is_preview": true
}
```

---

### 4.4 Quiz Creator & Submissions

| Method | Endpoint | Description | Payload / Notes |
| :--- | :--- | :--- | :--- |
| `GET` | `/api/admin/quizzes/lesson/{lessonId}` | List quizzes in lesson | |
| `POST` | `/api/admin/quizzes/lesson/{lessonId}` | Create quiz with questions | Full quiz builder payload (see below) |
| `GET` | `/api/admin/quizzes/{id}` | View full quiz | Questions, options, correct flags |
| `PUT` | `/api/admin/quizzes/{id}` | Update quiz & questions | Syncs questions |
| `POST` | `/api/admin/quizzes/{id}/publish` | Publish quiz | Requires at least 1 question |
| `POST` | `/api/admin/quizzes/{id}/archive` | Archive quiz | |
| `DELETE`| `/api/admin/quizzes/{id}` | Delete quiz | |
| `GET` | `/api/admin/quizzes/{id}/submissions` | List student submissions | Shows students, scores, pass/fail status |
| `GET` | `/api/admin/quizzes/{id}/submissions/{submissionId}` | View student submission detail | Shows student's choices vs correct answers |

#### Quiz Creation Payload:
```json
{
  "title": "Calculus Practice Quiz 1",
  "description": "Test your mastery on derivative power rules.",
  "instructions": "Attempt all questions within 15 minutes.",
  "pass_mark": 70,
  "timer_minutes": 15,
  "max_attempts": 3,
  "show_answers_after_submission": true,
  "questions": [
    {
      "type": "multiple_choice",
      "question": "What is the derivative of x^3?",
      "marks": 2,
      "explanation": "Using the power rule: d/dx(x^n) = n*x^(n-1)",
      "options": [
        { "option_text": "3x^2", "is_correct": true },
        { "option_text": "2x^3", "is_correct": false },
        { "option_text": "3x", "is_correct": false }
      ]
    },
    {
      "type": "true_false",
      "question": "The derivative of a constant is always 0.",
      "marks": 1,
      "explanation": "Constants do not change, so their rate of change is 0.",
      "options": [
        { "option_text": "True", "is_correct": true },
        { "option_text": "False", "is_correct": false }
      ]
    }
  ]
}
```

---

### 4.5 Progress Analytics for Admins & Tutors

- **Subject Engagement Overview**:
  - `GET /api/admin/course-progress/subject/{subjectId}/overview`
  - Returns total active students, total lesson completions, and total hours spent.
- **Specific Student's Progress**:
  - `GET /api/admin/course-progress/student/{studentId}/subject/{subjectId}`
  - Inspect any student's completion status per module and lesson.

---

## 5. TypeScript Interfaces

Copy and paste these interfaces into your frontend project (e.g. `types/courses.ts`):

```typescript
export type ProgressStatus = 'not_started' | 'in_progress' | 'completed';
export type MaterialType = 'video' | 'document' | 'link' | 'image' | 'audio';
export type QuestionType = 'multiple_choice' | 'true_false' | 'short_answer';

export interface LessonMaterial {
  id: number;
  lesson_id: number;
  title: string;
  type: MaterialType;
  url?: string;
  file_url?: string;
  duration_seconds?: number;
  order: number;
  is_preview: boolean;
}

export interface QuizOption {
  id: number;
  option_text: string;
  order: number;
  is_correct?: boolean; // Only present in staff view or after submission
}

export interface QuizQuestion {
  id: number;
  type: QuestionType;
  question: string;
  marks: number;
  order: number;
  explanation?: string;
  image_url?: string;
  options: QuizOption[];
}

export interface Quiz {
  id: number;
  lesson_id: number;
  title: string;
  description?: string;
  instructions?: string;
  total_marks: number;
  pass_mark: number;
  timer_minutes?: number;
  max_attempts?: number;
  show_answers_after_submission: boolean;
  my_attempts?: number;
  best_score?: number;
  has_passed?: boolean;
  questions?: QuizQuestion[];
}

export interface LessonProgress {
  status: ProgressStatus;
  completion_percentage: number;
  time_spent_seconds: number;
  completed_at?: string;
}

export interface Lesson {
  id: number;
  module_id: number;
  title: string;
  description?: string;
  order: number;
  duration_minutes?: number;
  status?: 'draft' | 'published' | 'archived';
  materials?: LessonMaterial[];
  quizzes?: Quiz[];
  progress?: LessonProgress;
}

export interface Module {
  id: number;
  subject_id: number;
  title: string;
  description?: string;
  order: number;
  status?: 'draft' | 'published' | 'archived';
  total_lessons?: number;
  completed_lessons?: number;
  percentage?: number;
  lessons: Lesson[];
}

export interface SubjectCourseProgress {
  subject_id: number;
  subject_name: string;
  total_lessons: number;
  completed_lessons: number;
  in_progress_lessons: number;
  overall_percentage: number;
  modules: Module[];
}
```

---

## 6. Recommended UX & Frontend States

1. **Curriculum Navigation Sidebar**:
   - Show green checkmarks (`✓`) for `status === 'completed'`.
   - Show active play icon for `status === 'in_progress'`.
   - Keep next lesson easily accessible via a "Next Lesson ➔" button on player finish.
2. **Video Player**:
   - Save time locally in `localStorage` in case of accidental refresh, syncing via periodic heartbeat (`/progress`).
   - Call `/complete` once video playback is finished.
3. **Quizzes UI**:
   - Provide a confirmation modal before "Start Attempt" displaying the time limit and remaining attempts.
   - Run a countdown timer if `timer_minutes` is defined. Auto-submit when time expires.
   - Display a clean score card with percentage, pass/fail status, and explanation review upon submission.
