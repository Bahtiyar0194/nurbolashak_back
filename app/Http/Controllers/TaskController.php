<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskLang;
use App\Models\TaskOption;
use App\Models\TaskType;
use App\Models\TaskWord;
use App\Models\MissingLetter;
use App\Models\Language;

use App\Models\Course;
use App\Models\CourseLevel;
use App\Models\CourseSection;
use App\Models\Lesson;

use Validator;
use DB;

use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function __construct(Request $request)
    {
        app()->setLocale($request->header('Accept-Language'));
    }

    public function get_task_attributes(Request $request)
    {
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();
    
        $all_task_types = TaskType::leftJoin('types_of_tasks_lang', 'types_of_tasks.task_type_id', '=', 'types_of_tasks_lang.task_type_id')
        ->select(
            'types_of_tasks.task_type_id',
            'types_of_tasks.task_type_slug',
            'types_of_tasks.task_type_component',
            'types_of_tasks_lang.task_type_name'
        )
        ->where('types_of_tasks.show_status_id', '=', 1)
        ->where('types_of_tasks_lang.lang_id', '=', $language->lang_id)
        ->distinct()
        ->orderBy('types_of_tasks.task_type_id', 'asc')
        ->get();

        $task_types = DB::table('tasks')
        ->leftJoin('types_of_tasks', 'types_of_tasks.task_type_id', '=', 'tasks.task_type_id')
        ->leftJoin('types_of_tasks_lang', 'types_of_tasks.task_type_id', '=', 'types_of_tasks_lang.task_type_id')
        ->select(
            'types_of_tasks.task_type_id',
            'types_of_tasks_lang.task_type_name'
        )
        ->where('types_of_tasks_lang.lang_id', '=', $language->lang_id)
        ->distinct()
        ->orderBy('types_of_tasks.task_type_id', 'asc')
        ->get();

        $operators = Task::leftJoin('users', 'users.user_id', '=', 'tasks.operator_id')
        ->select(
            'users.user_id',
            'users.first_name',
            'users.last_name',
            DB::raw("CONCAT(users.last_name, ' ', users.first_name) AS full_name"),
            'users.avatar'
        )
        ->distinct()
        ->orderBy('users.last_name', 'asc')
        ->get();

        $statuses = DB::table('tasks')
        ->leftJoin('types_of_status', 'tasks.status_type_id', '=', 'types_of_status.status_type_id')
        ->leftJoin('types_of_status_lang', 'types_of_status.status_type_id', '=', 'types_of_status_lang.status_type_id')
        ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
        ->select(
            'tasks.status_type_id',
            'types_of_status_lang.status_type_name'
        )
        ->groupBy('tasks.status_type_id', 'types_of_status_lang.status_type_name')
        ->get();

        $courses = Task::leftJoin('lessons', 'tasks.lesson_id', '=', 'lessons.lesson_id')
        ->leftJoin('course_sections', 'lessons.section_id', '=', 'course_sections.section_id')
        ->leftJoin('course_levels', 'course_sections.level_id', '=', 'course_levels.level_id')
        ->leftJoin('courses', 'course_levels.course_id', '=', 'courses.course_id')
        ->leftJoin('courses_lang', 'courses.course_id', '=', 'courses_lang.course_id')
        ->where('courses_lang.lang_id', '=', $language->lang_id)
        ->select(
            'courses.course_id',
            'courses_lang.course_name'
        )
        ->distinct()
        ->orderBy('courses.course_id', 'asc')
        ->get();


        foreach ($courses as $c => $course) {
            $levels = Task::leftJoin('lessons', 'tasks.lesson_id', '=', 'lessons.lesson_id')
            ->leftJoin('course_sections', 'lessons.section_id', '=', 'course_sections.section_id')
            ->leftJoin('course_levels', 'course_sections.level_id', '=', 'course_levels.level_id')
            ->leftJoin('course_levels_lang', 'course_levels.level_id', '=', 'course_levels_lang.level_id')
            ->where('course_levels.course_id', '=', $course->course_id)
            ->where('course_levels_lang.lang_id', '=', $language->lang_id)
            ->select(
                'course_levels.level_id',
                'course_levels_lang.level_name'
            )
            ->distinct()
            ->orderBy('course_levels.level_id', 'asc')
            ->get();

            $course->levels = $levels;

            foreach ($levels as $l => $level) {
                $sections = Task::leftJoin('lessons', 'tasks.lesson_id', '=', 'lessons.lesson_id')
                ->leftJoin('course_sections', 'lessons.section_id', '=', 'course_sections.section_id')
                ->where('course_sections.level_id', '=', $level->level_id)
                ->select(
                    'course_sections.section_id',
                    'course_sections.section_name'
                )
                ->distinct()
                ->orderBy('course_sections.section_id', 'asc')
                ->get();

                $level->sections = $sections;

                foreach ($sections as $s => $section) {
                    $lessons = Task::leftJoin('lessons', 'tasks.lesson_id', '=', 'lessons.lesson_id')
                    ->leftJoin('types_of_lessons', 'lessons.lesson_type_id', '=', 'types_of_lessons.lesson_type_id')
                    ->leftJoin('types_of_lessons_lang', 'types_of_lessons.lesson_type_id', '=', 'types_of_lessons_lang.lesson_type_id')
                    ->leftJoin('lessons_lang', 'lessons.lesson_id', '=', 'lessons_lang.lesson_id')
                    ->where('lessons.section_id', '=', $section->section_id)
                    ->where('types_of_lessons_lang.lang_id', '=', $language->lang_id)
                    ->where('lessons_lang.lang_id', '=', $language->lang_id)
                    ->select(
                        'lessons.lesson_id',
                        'lessons_lang.lesson_name',
                        'types_of_lessons_lang.lesson_type_name'
                    )
                    ->distinct()
                    ->orderBy('lessons.sort_num', 'asc')
                    ->get();

                    $section->lessons = $lessons;
                }
            }
        }

        $attributes = new \stdClass();

        $attributes->all_task_types = $all_task_types;
        $attributes->task_types = $task_types;
        $attributes->statuses = $statuses;
        $attributes->operators = $operators;
        $attributes->courses = $courses;

        return response()->json($attributes, 200);
    }

    public function get_tasks(Request $request){
        // Получаем язык из заголовка
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        // Получаем параметры лимита на страницу
        $per_page = $request->per_page ? $request->per_page : 10;
        // Получаем параметры сортировки
        $sortKey = $request->input('sort_key', 'created_at');  // Поле для сортировки по умолчанию
        $sortDirection = $request->input('sort_direction', 'asc');  // Направление по умолчанию

        $tasks = Task::leftJoin('tasks_lang', 'tasks_lang.task_id', '=', 'tasks.task_id')
        ->leftJoin('types_of_tasks', 'types_of_tasks.task_type_id', '=', 'tasks.task_type_id')
        ->leftJoin('types_of_tasks_lang', 'types_of_tasks_lang.task_type_id', '=', 'types_of_tasks.task_type_id')
        ->leftJoin('lessons', 'lessons.lesson_id', '=', 'tasks.lesson_id')
        ->leftJoin('lessons_lang', 'lessons_lang.lesson_id', '=', 'lessons.lesson_id')
        ->leftJoin('course_sections', 'course_sections.section_id', '=', 'lessons.section_id')
        ->leftJoin('course_levels', 'course_levels.level_id', '=', 'course_sections.level_id')
        ->leftJoin('course_levels_lang', 'course_levels_lang.level_id', '=', 'course_levels.level_id')
        ->leftJoin('courses', 'courses.course_id', '=', 'course_levels.course_id')
        ->leftJoin('courses_lang', 'courses_lang.course_id', '=', 'courses.course_id')
        ->leftJoin('users as operator', 'tasks.operator_id', '=', 'operator.user_id')
        ->leftJoin('types_of_status', 'tasks.status_type_id', '=', 'types_of_status.status_type_id')
        ->leftJoin('types_of_status_lang', 'types_of_status.status_type_id', '=', 'types_of_status_lang.status_type_id')
        ->select(
            'tasks.task_id',
            'tasks.task_slug',
            'tasks.task_type_id',
            'types_of_tasks.task_type_component',
            'types_of_tasks_lang.task_type_name',
            'tasks_lang.task_name',
            'tasks.created_at',
            'lessons_lang.lesson_name',
            'course_sections.section_name',
            'course_levels_lang.level_name',
            'courses_lang.course_name',
            'operator.first_name as operator_first_name',
            'operator.last_name as operator_last_name',
            'operator.avatar as operator_avatar',
            'types_of_status.color as status_color',
            'types_of_status_lang.status_type_name'
        )     
        ->where('tasks_lang.lang_id', '=', $language->lang_id)  
        ->where('lessons_lang.lang_id', '=', $language->lang_id)  
        ->where('course_levels_lang.lang_id', '=', $language->lang_id)  
        ->where('courses_lang.lang_id', '=', $language->lang_id)  
        ->where('types_of_tasks_lang.lang_id', '=', $language->lang_id)     
        ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
        ->distinct()
        ->orderBy($sortKey, $sortDirection);

        // Применяем фильтрацию по параметрам из запроса
        $task_name = $request->task_name;
        $task_slug = $request->task_slug;
        $course_id = $request->course_id;
        $level_id = $request->level_id;
        $section_id = $request->section_id;
        $lesson_id = $request->lesson_id;
        $task_types_id = $request->task_types;
        $created_at_from = $request->created_at_from;
        $created_at_to = $request->created_at_to;
        $operators_id = $request->operators;
        $statuses_id = $request->statuses;



        if (!empty($task_name)) {
            $tasks->where('tasks_lang.task_name', 'LIKE', '%' . $task_name . '%');
        }

        if (!empty($task_slug)) {
            $tasks->where('tasks.task_slug', 'LIKE', '%' . $task_slug . '%');
        }

        if (!empty($course_id)) {
            $tasks->where('courses.course_id', '=', $course_id);
        }

        if (!empty($level_id)) {
            $tasks->where('course_levels.level_id', '=', $level_id);
        }

        if (!empty($section_id)) {
            $tasks->where('course_sections.section_id', '=', $section_id);
        }

        if (!empty($lesson_id)) {
            $tasks->where('lessons.lesson_id', '=', $lesson_id);
        }

        if(!empty($task_types_id)){
            $tasks->whereIn('types_of_tasks.task_type_id', $task_types_id);
        }

        if(!empty($operators_id)){
            $tasks->whereIn('tasks.operator_id', $operators_id);
        }

        if (!empty($statuses_id)) {
            $tasks->whereIn('tasks.status_type_id', $statuses_id);
        }

        if ($created_at_from && $created_at_to) {
            $tasks->whereBetween('tasks.created_at', [$created_at_from . ' 00:00:00', $created_at_to . ' 23:59:59']);
        } elseif ($created_at_from) {
            $tasks->where('tasks.created_at', '>=', $created_at_from . ' 00:00:00');
        } elseif ($created_at_to) {
            $tasks->where('tasks.created_at', '<=', $created_at_to . ' 23:59:59');
        }

        // Возвращаем пагинированный результат
        return response()->json($tasks->paginate($per_page)->onEachSide(1), 200);
    }

    public function create_missing_letters_task(Request $request)
    {
        $rules = [];

        if ($request->step == 1) {
            $rules = [
                'words_count' => 'required|numeric|min:1',
                'words' => 'required',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            return response()->json([
                'step' => 1
            ], 200);
        } elseif ($request->step == 2) {
            $rules = [
                'words_count' => 'required|numeric|min:1',
                'words' => 'required',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $words = json_decode($request->words);

            if (count($words) > 0) {
                foreach ($words as $word) {
                    if(!isset($word->removedLetters) || count($word->removedLetters) == 0){
                        return response()->json(['letters_failed' => [trans('auth.remove_at_least_one_letter_in_each_word')]], 422);
                    }

                    if(strlen($word->word) <= count($word->removedLetters)){
                        return response()->json(['letters_failed' => [trans('auth.you_cannot_delete_all_the_letters_in_a_word')]], 422);
                    }
                }

                return response()->json([
                    'step' => 2
                ], 200);
            }
        } elseif ($request->step == 3) {
            $rules = [
                'course_id' => 'required|numeric',
                'level_id' => 'required|numeric',
                'section_id' => 'required|numeric',
                'lesson_id' => 'required|numeric',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            return response()->json([
                'step' => 3
            ], 200);
        }
        elseif ($request->step == 4) {
            $rules = [
                'task_slug' => 'required',
                'task_name_kk' => 'required',
                'task_name_ru' => 'required',
                'show_audio_button' => 'required|boolean',
                'show_image' => 'required|boolean',
                'show_transcription' => 'required|boolean',
                'show_translate' => 'required|boolean',
                'step' => 'required|numeric',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            
            $new_task = new Task();
            $new_task->task_slug = $request->task_slug;
            $new_task->task_type_id = 1;
            $new_task->lesson_id = $request->lesson_id;
            $new_task->operator_id = auth()->user()->user_id;
            $new_task->save();

            $new_task_lang = new TaskLang();
            $new_task_lang->task_name = $request->task_name_kk;
            $new_task_lang->task_id = $new_task->task_id;
            $new_task_lang->lang_id = 1;
            $new_task_lang->save();

            $new_task_lang = new TaskLang();
            $new_task_lang->task_name = $request->task_name_ru;
            $new_task_lang->task_id = $new_task->task_id;
            $new_task_lang->lang_id = 2;
            $new_task_lang->save();

            $words = json_decode($request->words);

            if (count($words) > 0) {
                foreach ($words as $word) {
                    $new_task_word = new TaskWord();
                    $new_task_word->task_id = $new_task->task_id;
                    $new_task_word->word_id = $word->word_id;
                    $new_task_word->save();

                    foreach ($word->removedLetters as $letter) {
                        $new_missing_letter = new MissingLetter();
                        $new_missing_letter->task_word_id = $new_task_word->task_word_id;
                        $new_missing_letter->position = ($letter + 1);
                        $new_missing_letter->save();
                    }
                }
            }

            $new_task_option = new TaskOption();
            $new_task_option->task_id = $new_task->task_id;
            $new_task_option->show_audio_button = $request->show_audio_button;
            $new_task_option->show_image = $request->show_image;
            $new_task_option->show_transcription = $request->show_transcription;
            $new_task_option->show_translate = $request->show_translate;
            $new_task_option->impression_limit = $request->impression_limit;
            $new_task_option->save();

            // $description = "<p><span>Название группы:</span> <b>{$new_group->group_name}</b></p>
            // <p><span>Куратор:</span> <b>{$mentor->last_name} {$mentor->first_name}</b></p>
            // <p><span>Категория:</span> <b>{$category->category_name}</b></p>
            // <p><span>Участники:</span> <b>" . implode(", ", $member_names) . "</b></p>";

            // $user_operation = new UserOperation();
            // $user_operation->operator_id = auth()->user()->user_id;
            // $user_operation->operation_type_id = 3;
            // $user_operation->description = $description;
            // $user_operation->save();

            return response()->json('success', 200);
        }
    }

    public function get_missing_letters_task(Request $request)
    {
        // Получаем язык из заголовка
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        $task_options = TaskOption::where('task_id', '=', $request->task_id)
        ->first();

        if(!isset($task_options)){
            return response()->json('task option is not found', 404);
        }

        $task_words = TaskWord::leftJoin('dictionary', 'task_words.word_id', '=', 'dictionary.word_id')
        ->leftJoin('dictionary_translate', 'dictionary.word_id', '=', 'dictionary_translate.word_id')
        ->select(
            'task_words.task_word_id',
            'dictionary.word',
            'dictionary.transcription',
            'dictionary.image_file',
            'dictionary.audio_file',
            'dictionary_translate.word_translate'
        )
        ->where('task_words.task_id', '=', $request->task_id)
        ->where('dictionary_translate.lang_id', '=', $language->lang_id)  
        ->distinct()
        ->get();

        if(count($task_words) === 0){
            return response()->json('task words is not found', 404);
        }

        foreach ($task_words as $word) {
            $missing_letters = MissingLetter::where('task_word_id', '=', $word->task_word_id)
            ->select(
                'missing_letters.position',
            )
            ->orderBy('position', 'asc')
            ->pluck('position')->toArray();

            $word->missingLetters = $missing_letters;
        }

        $task = new \stdClass();

        $task->options = $task_options;
        $task->words = $task_words;

        return response()->json($task, 200);
    }
}
