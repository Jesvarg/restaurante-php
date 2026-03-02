<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use App\Models\Category;
use App\Http\Requests\StoreRestaurantRequest;
use App\Http\Requests\UpdateRestaurantRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class RestaurantController extends Controller
{
    /**
     * Constructor - aplicar middleware de autenticación donde sea necesario
     */
    public function __construct()
    {
        // Solo los métodos de escritura requieren autenticación
        $this->middleware('auth')->except(['index', 'show']);
    }

    /**
     * Mostrar lista de restaurantes
     * GET /restaurants
     */
    public function index(Request $request)
    {
        // Construir query base con relaciones necesarias
        $query = Restaurant::with(['categories', 'reviews', 'photos'])
                          ->active(); // Solo restaurantes activos

        // Filtro por búsqueda
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filtro por categoría
        if ($request->filled('category')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('slug', $request->category);
            });
        }

        // Filtro por rango de precio
        if ($request->filled('price_range')) {
            $query->byPriceRange($request->price_range);
        }

        // Ordenamiento
        $sortBy = $request->get('sort', 'name');
        switch ($sortBy) {
            case 'rating':
                $query->withAvg('reviews', 'rating')
                      ->orderByDesc('reviews_avg_rating');
                break;
            case 'newest':
                $query->latest();
                break;
            case 'price_low':
                $query->orderBy('price_range');
                break;
            case 'price_high':
                $query->orderByDesc('price_range');
                break;
            default:
                $query->orderBy('name');
        }

        $restaurants = $query->paginate(30)->withQueryString();
        $categories = Category::popular()->orderBy('name')->get();

        return view('restaurants.index', compact('restaurants', 'categories'));
    }

    /**
     * Mostrar formulario de creación
     * GET /restaurants/create
     */
    public function create()
    {
        // Solo admin y owner pueden crear restaurantes
        if (!Auth::check() || !in_array(Auth::user()->role, ['admin', 'owner'])) {
            abort(403, 'No tienes permisos para crear restaurantes.');
        }
        
        $categories = Category::orderBy('name')->get();
        return view('restaurants.create', compact('categories'));
    }

    /**
     * Almacenar nuevo restaurante
     * POST /restaurants
     * 1. Validar datos de entrada usando StoreRestaurantRequest
     * 2. Crear restaurante
     * 3. Asociar categorías
     * 4. Redirigir con mensaje de éxito
     */
    public function store(StoreRestaurantRequest $request)
    {
        try {
            DB::beginTransaction();

            // Crear el restaurante
            $restaurant = Restaurant::create([
                'name' => $request->name,
                'description' => $request->description,
                'address' => $request->address,
                'phone' => $request->phone,
                'email' => $request->email,
                'website' => $request->website,
                'price_range' => $request->price_range,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'user_id' => Auth::id(),
                'status' => 'pending', // Requiere aprobación
            ]);

            // Asociar categorías
            $restaurant->categories()->attach($request->categories);

            // Manejar fotos subidas
            if ($request->hasFile('photos')) {
                $this->handlePhotoUploads($restaurant, $request->file('photos'));
            }

            // Manejar URLs de fotos
            if ($request->filled('photo_urls')) {
                $this->handlePhotoUrls($restaurant, $request->photo_urls);
            }

            DB::commit();

            return redirect()->route('restaurants.show', $restaurant)
                ->with('success', 'Restaurante creado exitosamente. Está pendiente de aprobación.');

        } catch (Throwable $e) {
            DB::rollBack();
            \Log::error('Error creating restaurant: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return redirect()->back()
                ->withInput()
                ->with('error', 'Error al crear el restaurante. Por favor, inténtalo de nuevo.');
        }
    }

    /**
     * Mostrar restaurante específico
     * GET /restaurants/{restaurant}
     */
    public function show(Restaurant $restaurant)
    {
        // Cargar relaciones necesarias con optimización de consultas
        $restaurant->load([
            'categories',
            'photos' => function ($query) {
                $query->ordered();
            },
            'user'
        ]);
        
        // Paginar reseñas (5 por página)
        $reviews = $restaurant->reviews()
            ->with('user')
            ->latest()
            ->paginate(5, ['*'], 'reviews_page');

        // Verificar si el usuario actual ha marcado como favorito
        $isFavorite = Auth::check() && Auth::user()->favorites()->where('restaurant_id', $restaurant->id)->exists();
        
        // Verificar si el usuario puede editar
        $canEdit = Auth::check() && (Auth::id() === $restaurant->user_id || Auth::user()->role === 'admin');

        return view('restaurants.show', compact('restaurant', 'reviews', 'isFavorite', 'canEdit'));
    }

    /**
     * Mostrar formulario de edición
     * GET /restaurants/{restaurant}/edit
     */
    public function edit(Restaurant $restaurant)
    {
        // Verificar permisos
        if (Auth::id() !== $restaurant->user_id) {
            abort(403, 'No tienes permisos para editar este restaurante.');
        }

        $categories = Category::orderBy('name')->get();
        $restaurant->load('categories', 'photos');
        
        return view('restaurants.edit', compact('restaurant', 'categories'));
    }

    /**
     * Actualizar restaurante
     * PUT /restaurants/{restaurant}
     * Usa UpdateRestaurantRequest para validación y autorización
     */
    public function update(UpdateRestaurantRequest $request, Restaurant $restaurant)
    {
        // Los permisos y validación ya están manejados por UpdateRestaurantRequest
        $validated = $request->validated();

        try {
            // Process opening hours from time picker format
            $openingHours = $this->processOpeningHours($validated);
            
            // Actualizar restaurante
            $restaurant->update([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'address' => $validated['address'],
                'phone' => $validated['phone'],
                'email' => $validated['email'],
                'website' => $validated['website'],
                'opening_hours' => $openingHours,
                'latitude' => $validated['latitude'] ?? null,
                'longitude' => $validated['longitude'] ?? null,
                'price_range' => $validated['price_range'],
                'status' => 'pending', // Requiere nueva aprobación tras edición
            ]);

            // Actualizar categorías
            $restaurant->categories()->sync($validated['categories']);

            // Eliminar fotos marcadas para eliminación
            if ($request->filled('delete_photos')) {
                $photosToDelete = $restaurant->photos()->whereIn('id', $request->input('delete_photos'))->get();
                foreach ($photosToDelete as $photo) {
                    $this->deleteStoredPhotoIfLocal($photo->url);
                    $photo->delete();
                }
            }

            // Procesar nuevas fotos subidas
            if ($request->hasFile('photos')) {
                $this->handlePhotoUploads($restaurant, $request->file('photos'));
            }
            
            // Procesar nuevas URLs de fotos
            if ($request->filled('photo_urls')) {
                $this->handlePhotoUrls($restaurant, $request->input('photo_urls'));
            }

            return redirect()->route('restaurants.show', $restaurant)
                           ->with('success', 'Restaurante actualizado exitosamente.');
                           
        } catch (Throwable $e) {
            return back()->withErrors([
                'error' => 'Ocurrió un error al actualizar el restaurante.'
            ])->withInput();
        }
    }

    /**
     * Toggle favorite status for a restaurant
     * POST /restaurants/{restaurant}/toggle-favorite
     */
    public function toggleFavorite(Restaurant $restaurant)
    {
        $user = Auth::user();
        
        // Verificar si ya está en favoritos
        $isFavorite = $user->favorites()->where('restaurant_id', $restaurant->id)->exists();
        
        if ($isFavorite) {
            // Remover de favoritos
            $user->favorites()->detach($restaurant->id);
            $message = 'Restaurante removido de favoritos';
            $action = 'removed';
        } else {
            // Agregar a favoritos
            $user->favorites()->attach($restaurant->id);
            $message = 'Restaurante agregado a favoritos';
            $action = 'added';
        }
        
        // Si es una petición AJAX, devolver JSON
        if (request()->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'action' => $action,
                'is_favorite' => !$isFavorite
            ]);
        }
        
        // Si es una petición normal, redirigir con mensaje
        return back()->with('success', $message);
    }

    /**
     * Guardar review
     * POST /restaurants/{restaurant}/reviews
     */
    public function storeReview(Request $request, Restaurant $restaurant)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000'
        ]);
        
        // Verificar que el usuario no haya dejado ya una review
        $existingReview = $restaurant->reviews()->where('user_id', Auth::id())->first();
        
        if ($existingReview) {
            return back()->withErrors(['error' => 'Ya has dejado una reseña para este restaurante.']);
        }
        
        $restaurant->reviews()->create([
            'user_id' => Auth::id(),
            'rating' => $request->rating,
            'comment' => $request->comment
        ]);
        
        return back()->with('success', 'Reseña agregada exitosamente.');
    }
    
    /**
     * Mis restaurantes
     */
    public function myRestaurants()
    {
        $restaurants = Restaurant::where('user_id', Auth::id())
                                ->with(['categories', 'photos', 'reviews', 'rejectionReasons'])
                                ->latest()
                                ->paginate(10);
        
        return view('dashboard.my-restaurants', compact('restaurants'));
    }
    
    /**
     * Mis favoritos
     */
    public function myFavorites()
    {
        $restaurants = Auth::user()->favorites()
                          ->with(['categories', 'photos', 'reviews'])
                          ->latest('favorites.created_at')
                          ->paginate(10);
        
        return view('dashboard.my-favorites', compact('restaurants'));
    }
    
    /**
     * Mis reviews
     */
    public function myReviews()
    {
        $reviews = Auth::user()->reviews()
                      ->with('restaurant')
                      ->latest()
                      ->paginate(10);
        
        return view('dashboard.my-reviews', compact('reviews'));
    }

    /**
     * Process opening hours from time picker format to JSON format.
     */
    private function processOpeningHours(array $validated): ?array
    {
        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $openingHours = [];
        
        foreach ($days as $day) {
            $isOpen = $validated['is_open'][$day] ?? false;
            
            if ($isOpen && isset($validated['open_time'][$day]) && isset($validated['close_time'][$day])) {
                $openTime = $validated['open_time'][$day];
                $closeTime = $validated['close_time'][$day];
                
                if ($openTime && $closeTime) {
                    $openingHours[$day] = $openTime . ' - ' . $closeTime;
                } else {
                    $openingHours[$day] = 'Cerrado';
                }
            } else {
                $openingHours[$day] = 'Cerrado';
            }
        }
        
        return empty($openingHours) ? null : $openingHours;
    }

    /**
     * Método privado para procesar múltiples fotos
     */
    private function handlePhotoUploads(Restaurant $restaurant, array $photos)
    {
        foreach ($photos as $index => $photo) {
            if ($photo && $photo->isValid()) {
                try {
                    // Validar que sea una imagen
                    $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
                    $mimeType = $photo->getMimeType();
                    
                    if (!in_array($mimeType, $allowedMimes)) {
                        \Log::warning('Invalid mime type', ['mime' => $mimeType]);
                        continue; // Saltar archivos que no sean imágenes
                    }
                    
                    // Generar nombre único
                    $extension = $photo->getClientOriginalExtension();
                    if (empty($extension)) {
                        // Fallback basado en mime type
                        $mimeToExt = [
                            'image/jpeg' => 'jpg',
                            'image/png' => 'png',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp'
                        ];
                        $extension = $mimeToExt[$mimeType] ?? 'jpg';
                    }
                    
                    $filename = Str::uuid()->toString() . '_' . $index . '.' . $extension;
                    
                    // Guardar archivo
                    $path = $photo->storeAs('restaurants', $filename, 'public');
                    
                    // Crear registro en base de datos
                    $restaurant->photos()->create([
                        'url' => $path,
                        'alt_text' => "Foto de {$restaurant->name}",
                        'is_primary' => $index === 0 && $restaurant->photos()->count() === 0,
                        'order' => $restaurant->photos()->count() + 1,
                    ]);
                    
                } catch (Throwable $e) {
                    // Log error but continue with other photos
                    \Log::error('Error uploading photo: ' . $e->getMessage(), [
                        'index' => $index,
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
            }
        }
    }
    
    /**
     * Método privado para procesar URLs de fotos
     */
    private function handlePhotoUrls(Restaurant $restaurant, array $photoUrls)
    {
        foreach ($photoUrls as $index => $url) {
            $url = trim((string) $url);
            if (!empty($url)) {
                try {
                    // Validar que la URL sea válida y apunte a una imagen
                    if (filter_var($url, FILTER_VALIDATE_URL) && 
                        preg_match('/\.(jpeg|jpg|png|gif|webp)(\?.*)?$/i', $url)) {
                        
                        // Crear registro en base de datos
                        $restaurant->photos()->create([
                            'url' => $url,
                            'alt_text' => "Foto de {$restaurant->name}",
                            'is_primary' => $index === 0 && $restaurant->photos()->count() === 0,
                            'order' => $restaurant->photos()->count() + 1,
                        ]);
                    }
                } catch (Throwable $e) {
                    // Log error but continue with other URLs
                    \Log::error('Error processing photo URL: ' . $e->getMessage());
                    continue;
                }
            }
        }
    }

    private function deleteStoredPhotoIfLocal(string $photoPath): void
    {
        if (filter_var($photoPath, FILTER_VALIDATE_URL)) {
            return;
        }

        $normalizedPath = str_starts_with($photoPath, 'storage/')
            ? str_replace('storage/', '', $photoPath)
            : $photoPath;

        if (Storage::disk('public')->exists($normalizedPath)) {
            Storage::disk('public')->delete($normalizedPath);
        }
    }
}
