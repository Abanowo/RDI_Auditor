{{-- Formas del encabezado (franja coral, diagonal, banda gris y rombos) + logo + datos de la empresa --}}
@if(!empty($graficos['encabezado']))
    <img class="enc-fondo" src="{{ $graficos['encabezado'] }}" alt="">
@else
    <div class="franja"><span class="f1"></span><span class="f2"></span><span class="f3"></span></div>
@endif
<table class="enc">
    <tr>
        <td class="enc-logo">
            @if($logo)
                <img src="{{ $logo }}" alt="{{ $empresa['nombre'] }}" style="height:52pt; max-width:240pt">
            @else
                <table class="dots"><tr>
                    <td><table>
                        @foreach([['r','g','r','g'], ['g','r','g','r'], ['r','g','r','g']] as $fila)
                            <tr>@foreach($fila as $c)<td><div class="dot dot-{{ $c }}"></div></td>@endforeach</tr>
                        @endforeach
                    </table></td>
                    <td class="marca">INTACTICS<sup>&reg;</sup></td>
                </tr></table>
            @endif
        </td>
        <td class="enc-datos">
            <b>{{ $empresa['nombre'] }}</b><br>
            @foreach($empresa['direccion'] as $linea){{ $linea }}<br>@endforeach
            <span class="rojo">{{ $empresa['email'] }}</span>
        </td>
    </tr>
</table>
<div class="sep"></div>
