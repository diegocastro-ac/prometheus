{{-- Hoja compartida por los cuatro documentos en PDF. --}}
<div class="document">
    <h1 class="document-title">{{ $body->title }}</h1>

    @if($body->fields !== [])
        <table class="document-fields">
            @foreach($body->fields as $label => $value)
                <tr>
                    <th>{{ $label }}</th>
                    <td>{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if($body->headers !== [] && $body->rows !== [])
        <table class="document-table">
            <thead>
                <tr>
                    @foreach($body->headers as $header)
                        <th>{{ $header }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($body->rows as $row)
                    <tr>
                        @foreach($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Los avisos van despues de la tabla y nunca se omiten: el incremento
         por encima del IPC solo opera con acuerdo escrito y el arrendatario
         tiene que enterarse. Se escapan porque el texto del aviso incluye la
         URL de la fuente, que es dato capturado y no contenido de confianza. --}}
    @foreach($body->warnings as $warning)
        <div class="document-warning">{{ $warning }}</div>
    @endforeach

    @foreach($body->notes as $note)
        <p class="document-note">{{ $note }}</p>
    @endforeach

    @if($body->footer !== null)
        <p class="document-footer">{{ $body->footer }}</p>
    @endif
</div>