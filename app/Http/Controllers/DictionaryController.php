<?php
namespace App\Http\Controllers;

use App\Models\Dictionary;
use App\Models\DictionaryTranslate;
use App\Models\Language;
use App\Models\UploadConfiguration;
use App\Models\User;
use App\Models\Course;

use Illuminate\Http\Request;
use Validator;
use DB;
use Image;
use Storage;

class DictionaryController extends Controller
{
    public function __construct(Request $request)
    {
        app()->setLocale($request->header('Accept-Language'));
    }

    public function get_dictionary_attributes(Request $request)
    {
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        $operators = Dictionary::leftJoin('users', 'users.user_id', '=', 'dictionary.operator_id')
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

        $courses = Dictionary::leftJoin('courses', 'dictionary.course_id', '=', 'courses.course_id')
        ->leftJoin('courses_lang', 'courses.course_id', '=', 'courses_lang.course_id')
        ->where('courses_lang.lang_id', '=', $language->lang_id)
        ->select(
            'courses.course_id',
            'courses_lang.course_name'
        )
        ->distinct()
        ->orderBy('courses.course_id', 'asc')
        ->get();

        $all_courses = Course::leftJoin('courses_lang', 'courses.course_id', '=', 'courses_lang.course_id')
        ->where('courses.show_status_id', '=', 1)
        ->where('courses_lang.lang_id', '=', $language->lang_id)
        ->select(
            'courses.course_id',
            'courses_lang.course_name'
        )
        ->distinct()
        ->orderBy('courses.course_id', 'asc')
        ->get();

        // Получаем статусы пользователя
        $statuses = DB::table('dictionary')
        ->leftJoin('types_of_status', 'dictionary.status_type_id', '=', 'types_of_status.status_type_id')
        ->leftJoin('types_of_status_lang', 'types_of_status.status_type_id', '=', 'types_of_status_lang.status_type_id')
        ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
        ->select(
            'dictionary.status_type_id',
            'types_of_status_lang.status_type_name'
        )
        ->groupBy('dictionary.status_type_id', 'types_of_status_lang.status_type_name')
        ->get();

        $attributes = new \stdClass();

        $attributes->all_courses = $all_courses;
        $attributes->courses = $courses;
        $attributes->operators = $operators;
        $attributes->statuses = $statuses;

        return response()->json($attributes, 200);
    }

    public function get_words(Request $request)
    {
        // Получаем язык из заголовка
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        // Получаем параметры лимита на страницу
        $per_page = $request->per_page ? $request->per_page : 10;
        // Получаем параметры сортировки
        $sortKey = $request->input('sort_key', 'created_at');  // Поле для сортировки по умолчанию
        $sortDirection = $request->input('sort_direction', 'asc');  // Направление по умолчанию

        $words = Dictionary::leftJoin('courses', 'dictionary.course_id', '=', 'courses.course_id')
        ->leftJoin('courses_lang', 'courses.course_id', '=', 'courses_lang.course_id')
        ->leftJoin('users as operator', 'dictionary.operator_id', '=', 'operator.user_id')
        ->leftJoin('types_of_status', 'dictionary.status_type_id', '=', 'types_of_status.status_type_id')
        ->leftJoin('types_of_status_lang', 'types_of_status.status_type_id', '=', 'types_of_status_lang.status_type_id')
        ->select(
            'dictionary.word_id',
            'dictionary.word',
            'dictionary.transcription',
            'dictionary.image_file',
            'dictionary.audio_file',
            'dictionary.created_at',
            'courses_lang.course_name',
            'operator.first_name as operator_first_name',
            'operator.last_name as operator_last_name',
            'operator.avatar as operator_avatar',
            'types_of_status.color as status_color',
            'types_of_status_lang.status_type_name'
        )            
        ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
        ->where('courses_lang.lang_id', '=', $language->lang_id)
        ->distinct()
        ->orderBy($sortKey, $sortDirection);

        // Применяем фильтрацию по параметрам из запроса
        $word = $request->word;
        $transcription = preg_replace('/^\[(.*)\]$/', '$1', $request->transcription);
        $courses_id = $request->courses;
        $created_at_from = $request->created_at_from;
        $created_at_to = $request->created_at_to;
        $operators_id = $request->operators;
        $statuses_id = $request->statuses;


        // Фильтрация по слову
        if (!empty($word)) {
            $words->where('dictionary.word', 'LIKE', '%' . $word . '%');
        }

        // Фильтрация по транскрипции
        if (!empty($transcription)) {
            $words->where('dictionary.transcription', 'LIKE', '%' . $transcription . '%');
        }

        // Фильтрация по курсу
        if (!empty($courses_id)) {
            $words->whereIn('courses.course_id', $courses_id);
        }

        // Фильтрация по операторам
        if(!empty($operators_id)){
            $words->whereIn('dictionary.operator_id', $operators_id);
        }

        // Фильтрация по статусу
        if (!empty($statuses_id)) {
            $words->whereIn('dictionary.status_type_id', $statuses_id);
        }

        // Фильтрация по дате создания
        if ($created_at_from && $created_at_to) {
            $words->whereBetween('dictionary.created_at', [$created_at_from . ' 00:00:00', $created_at_to . ' 23:59:59']);
        } elseif ($created_at_from) {
            $words->where('dictionary.created_at', '>=', $created_at_from . ' 00:00:00');
        } elseif ($created_at_to) {
            $words->where('dictionary.created_at', '<=', $created_at_to . ' 23:59:59');
        }

        // Возвращаем пагинированный результат
        return response()->json($words->paginate($per_page)->onEachSide(1), 200);
    }

    public function get_word(Request $request)
    {
        // Получаем язык из заголовка
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        $word = Dictionary::leftJoin('courses', 'dictionary.course_id', '=', 'courses.course_id')
        ->leftJoin('courses_lang', 'courses.course_id', '=', 'courses_lang.course_id')
        ->leftJoin('types_of_status', 'dictionary.status_type_id', '=', 'types_of_status.status_type_id')
        ->leftJoin('types_of_status_lang', 'types_of_status.status_type_id', '=', 'types_of_status_lang.status_type_id')
        ->select(
            'dictionary.word_id',
            'dictionary.word',
            'dictionary.transcription',
            'dictionary.image_file',
            'dictionary.audio_file',
            'dictionary.created_at',
            'dictionary.operator_id',
            'courses_lang.course_name',
            'types_of_status.color as status_color',
            'types_of_status_lang.status_type_name'
        )            
        ->where('types_of_status_lang.lang_id', '=', $language->lang_id)
        ->where('courses_lang.lang_id', '=', $language->lang_id)
        ->where('dictionary.word_id', '=', $request->word_id)
        ->distinct()
        ->first();

        $operator = User::find($word->operator_id);

        $translates = DictionaryTranslate::leftJoin('languages', 'dictionary_translate.lang_id', '=', 'languages.lang_id')
        ->select(
            'dictionary_translate.word_translate',
            'languages.lang_tag'
        ) 
        ->where('dictionary_translate.word_id', '=', $request->word_id)
        ->get();

        $word->operator = $operator->only(['last_name', 'first_name', 'avatar']);
        $word->translates = $translates;

        return response()->json($word, 200);
    }

    public function add(Request $request)
    {
        // Получаем язык из заголовка
        $language = Language::where('lang_tag', '=', $request->header('Accept-Language'))->first();

        $image_max_file_size = UploadConfiguration::where('file_type_id', '=', 3)
        ->first()->max_file_size_mb;
    
        $audio_max_file_size = UploadConfiguration::where('file_type_id', '=', 2)
        ->first()->max_file_size_mb;

        // Получаем текущего аутентифицированного пользователя
        $auth_user = auth()->user();

        $validator = Validator::make($request->all(), [
            'word' => 'required|string|between:2,100|unique:dictionary',
            'transcription' => 'required|string|between:2,100',
            'word_kk' => 'required|string|between:2,100',
            'word_ru' => 'required|string|between:2,100',
            'course_id' => 'required|numeric',
            'image_file' => 'nullable|file|mimes:jpg,png,jpeg,gif,svg,webp|max_mb:'.$image_max_file_size,
            'audio_file' => 'required|file|mimes:mp3,wav,ogg,aac,flac|max_mb:'.$audio_max_file_size
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }


        $new_word = new Dictionary();
        $new_word->word = $request->word;
        $new_word->transcription = preg_replace('/^\[(.*)\]$/', '$1', $request->transcription);

        $image_file = $request->file('image_file');

        if($image_file){
            $image_file_name = $image_file->hashName();
            $resized_image = Image::make($image_file)->resize(500, null, function ($constraint) {
                $constraint->aspectRatio();
            })->stream('png', 80);
            Storage::disk('local')->put('/public/'.$image_file_name, $resized_image);
            $new_word->image_file = $image_file_name;
        }

        $audio_file = $request->file('audio_file');
        $audio_file_name = $audio_file->hashName();
        $audio_file->storeAs('/public/', $audio_file_name);
        $new_word->audio_file = $audio_file_name;

        $new_word->course_id = $request->course_id;
        $new_word->operator_id = $auth_user->user_id;
        $new_word->save();

        $new_word_translate = new DictionaryTranslate();
        $new_word_translate->word_translate = $request->word_kk;
        $new_word_translate->word_id = $new_word->word_id;
        $new_word_translate->lang_id = 1;
        $new_word_translate->save();

        $new_word_translate = new DictionaryTranslate();
        $new_word_translate->word_translate = $request->word_ru;
        $new_word_translate->word_id = $new_word->word_id;
        $new_word_translate->lang_id = 2;
        $new_word_translate->save();

        // $description = "Имя: {$new_user->last_name} {$new_user->first_name};\n E-Mail: {$request->email};\n Телефон: {$request->phone};\n Роли: " . implode(",", $role_names) . ".";

        // $user_operation = new UserOperation();
        // $user_operation->operator_id = $auth_user->user_id;
        // $user_operation->operation_type_id = 1;
        // $user_operation->description = $description;
        // $user_operation->save();

        return response()->json($new_word, 200);
    }
}